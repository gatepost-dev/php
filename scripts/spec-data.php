<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Reads the JSON files in spec/data and builds SpecData.php for scripts/generate-data.php.
// Each reader throws on data of an unexpected shape, so a broken data file stops the build.

namespace Gatepost\Postcode\Scripts;

use RuntimeException;

const DATA_VERSION = 1;
const PRECISIONS = ['state', 'lga', 'district', 'area', 'unit'];
const CHARACTER_RULES = ['letters', 'digits', 'letters-or-digits'];

/**
 * @return array<mixed>
 */
function readJson(string $path): array
{
    $text = \file_get_contents($path);
    if ($text === false) {
        throw new RuntimeException("Cannot read {$path}. Run git submodule update --init.");
    }
    $decoded = \json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    if (!\is_array($decoded) || ($decoded['version'] ?? null) !== DATA_VERSION) {
        throw new RuntimeException("{$path} must hold an object with version " . DATA_VERSION);
    }

    return $decoded;
}

/**
 * @return list<array<mixed>>
 */
function objects(mixed $value, string $where): array
{
    if (!\is_array($value) || !\array_is_list($value) || $value === []) {
        throw new RuntimeException("{$where} must be a list with at least one object.");
    }

    return \array_map(static function (mixed $item) use ($where): array {
        if (!\is_array($item)) {
            throw new RuntimeException("Each item in {$where} must be an object.");
        }

        return $item;
    }, $value);
}

// The generated files must be ASCII for TELL-14. The spec's own tests keep its names in ASCII,
// so other text is an error here, not something to escape.
function text(mixed $value, string $where): string
{
    if (!\is_string($value) || \preg_match('/\A[ -~]+\z/', $value) !== 1) {
        throw new RuntimeException("{$where} must be printable ASCII text.");
    }

    return $value;
}

function wholeNumber(mixed $value, string $where): int
{
    if (!\is_int($value) || $value < 0) {
        throw new RuntimeException("{$where} must be a whole number of 0 or more.");
    }

    return $value;
}

/**
 * @param list<string> $choices
 */
function choice(mixed $value, array $choices, string $where): string
{
    $chosen = text($value, $where);
    if (!\in_array($chosen, $choices, true)) {
        throw new RuntimeException("{$where} must be one of " . \implode(', ', $choices) . '.');
    }

    return $chosen;
}

// A label such as "U+2013" names one code point. A label of another shape would give a wrong
// table and no error, so it throws.
/**
 * @return list<int>
 */
function separatorCodePoints(mixed $labels): array
{
    if (!\is_array($labels) || !\array_is_list($labels) || $labels === []) {
        throw new RuntimeException('separators must be a list with at least one label.');
    }

    return \array_map(static function (mixed $label): int {
        if (!\is_string($label) || \preg_match('/\AU\+([0-9A-F]{4,6})\z/', $label, $hex) !== 1) {
            throw new RuntimeException('Write each separator as U+ and 4 to 6 hex digits.');
        }

        return \intval($hex[1], 16);
    }, $labels);
}

/**
 * @param array<mixed> $format
 * @param list<int> $separators
 */
function specData(string $root, array $format, array $separators): string
{
    $precision = readJson("{$root}/spec/data/precision.json");
    $limit = wholeNumber($format['maxInputCodePoints'] ?? null, 'maxInputCodePoints');
    $fallback = choice($precision['fallback'] ?? null, PRECISIONS, 'fallback');
    $digitFixes = fixes($format, 'digits');
    $letterFixes = fixes($format, 'letters');
    $removals = \array_map(
        static fn(int $codePoint): string => ENTRY . escaped([$codePoint]) . " => '',",
        $separators,
    );
    $constants = [
        ...constantLines(
            'The most code points that parse reads. Longer input fails before normalize runs.',
            'MAX_INPUT_CODE_POINTS',
            (string) $limit,
        ),
        ...constantLines('State codes and names.', 'STATES', stateEntries($root)),
        ...constantLines(
            'Each segment from the least to the most precise, with its place in a compact code.',
            'SEGMENTS',
            segmentEntries($format['segments'] ?? null),
        ),
        ...constantLines(
            'GPS accuracy limits in metres. Each limit is inclusive.',
            'PRECISION_THRESHOLDS',
            thresholdEntries($precision['thresholds'] ?? null),
        ),
        ...constantLines(
            'The precision for an accuracy that no limit covers.',
            'PRECISION_FALLBACK',
            quoted($fallback),
        ),
        ...constantLines(
            'Each separator that normalize removes, mapped to the empty string for strtr().',
            'SEPARATORS',
            $removals,
        ),
        ...constantLines(
            'Old 6-digit NIPOST postcodes. With D, $ does not match before a final line feed.',
            'LEGACY_PATTERN',
            quoted(legacyPattern($format['legacyPattern'] ?? null)),
        ),
        ...constantLines('Typo fixes in digit segments.', 'DIGIT_FIXES', $digitFixes),
        ...constantLines('Typo fixes in letter segments.', 'LETTER_FIXES', $letterFixes),
    ];
    $summary = 'The values in the JSON files in spec/data.';

    return classFile('spec/data', $summary, 'SpecData', $constants);
}

function legacyPattern(mixed $pattern): string
{
    $legacy = text($pattern, 'legacyPattern');
    if (\str_contains($legacy, '/') || \preg_match("/{$legacy}/D", '') === false) {
        throw new RuntimeException('legacyPattern must be a regular expression without a slash.');
    }

    return "/{$legacy}/D";
}

/**
 * @return list<string>
 */
function stateEntries(string $root): array
{
    $states = readJson("{$root}/spec/data/states.json")['states'] ?? null;

    return \array_map(static function (array $state): string {
        $code = quoted(text($state['code'] ?? null, 'state code'));

        return ENTRY . "{$code} => " . quoted(text($state['name'] ?? null, 'state name')) . ',';
    }, objects($states, 'states'));
}

/**
 * @return list<string>
 */
function segmentEntries(mixed $segments): array
{
    $entries = [];
    $start = 0;
    foreach (objects($segments, 'segments') as $segment) {
        $end = $start + wholeNumber($segment['length'] ?? null, 'segment length');
        $minimum = $segment['minimum'] ?? null;
        $entries[] = ENTRY . \sprintf(
            "%s => ['start' => %d, 'length' => %d, 'end' => %d, "
            . "'characters' => %s, 'minimum' => %s],",
            quoted(choice($segment['name'] ?? null, PRECISIONS, 'segment name')),
            $start,
            $end - $start,
            $end,
            quoted(choice($segment['characters'] ?? null, CHARACTER_RULES, 'segment characters')),
            $minimum === null ? 'null' : wholeNumber($minimum, 'segment minimum'),
        );
        $start = $end;
    }

    return $entries;
}

/**
 * @return list<string>
 */
function thresholdEntries(mixed $thresholds): array
{
    return \array_map(static function (array $threshold): string {
        $limit = $threshold['maxAccuracyM'] ?? null;
        if (!\is_int($limit) && !\is_float($limit)) {
            throw new RuntimeException('maxAccuracyM must be a number.');
        }
        $precision = choice($threshold['precision'] ?? null, PRECISIONS, 'threshold precision');

        return ENTRY . \sprintf(
            "['maxAccuracyM' => %s, 'precision' => %s],",
            \json_encode($limit, JSON_THROW_ON_ERROR),
            quoted($precision),
        );
    }, objects($thresholds, 'thresholds'));
}

/**
 * @param array<mixed> $format
 *
 * @return list<string>
 */
function fixes(array $format, string $kind): array
{
    $suggestions = $format['suggestions'] ?? null;
    $fixes = \is_array($suggestions) ? ($suggestions[$kind] ?? null) : null;
    if (!\is_array($fixes) || $fixes === []) {
        throw new RuntimeException("suggestions.{$kind} must hold at least one fix.");
    }
    $entries = [];
    foreach ($fixes as $from => $to) {
        // PHP turns the key "0" into the integer 0, so the cast restores the text.
        $from = quoted(text((string) $from, 'fix'));
        $entries[] = ENTRY . "{$from} => " . quoted(text($to, 'fix')) . ',';
    }

    return $entries;
}
