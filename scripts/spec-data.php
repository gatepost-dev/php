<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Reads the JSON files in spec/data and builds SpecData.php for scripts/generate-data.php.
// Each reader throws on data of an unexpected shape, or on a state code, a segment name or a
// separator that appears twice, so a broken data file stops the build.

namespace Gatepost\Postcode\Scripts;

use RuntimeException;

const DATA_VERSION = 1;
const FORMAT_FILE = 'spec/data/format.json';
const PRECISION_FILE = 'spec/data/precision.json';
const STATES_FILE = 'spec/data/states.json';
const PRECISIONS = ['state', 'lga', 'district', 'area', 'unit'];
const CHARACTER_RULES = ['letters', 'digits', 'letters-or-digits'];
// A fix table for digit segments changes letters to digits, and a table for letter segments
// changes digits to letters. This pair gives the characters that a fix changes, then the
// characters that it writes. The check reads the characters, not the JSON type, because PHP
// decodes the object {"0": "O"} into the same array as the list ["O"].
const FIX_CHARACTERS = [
    'digits' => ['[A-Z]', '[0-9]'],
    'letters' => ['[0-9]', '[A-Z]'],
];

/**
 * @return array<mixed>
 */
function readJson(string $path): array
{
    // A file that is missing or that no user can read gives false here, so the clear message
    // below replaces a PHP warning.
    $text = \is_file($path) && \is_readable($path) ? \file_get_contents($path) : false;
    if ($text === false) {
        throw new RuntimeException(
            "Cannot read {$path}. Run git submodule update --init, or check the permissions.",
        );
    }
    $decoded = \json_decode($text, true);
    if ($decoded === null && \json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("{$path} is not valid JSON: " . \json_last_error_msg() . '.');
    }
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

// The generated class is an array, and a repeated key would keep only the last value. So a value
// that appears twice stops the build.
/**
 * @param list<string> $values
 */
function rejectRepeats(array $values, string $file, string $what): void
{
    foreach (\array_count_values($values) as $value => $count) {
        if ($count > 1) {
            throw new RuntimeException("{$file}: the {$what} {$value} appears more than once.");
        }
    }
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
// table and no error, so it throws. A label above U+10FFFF writes a class that does not parse,
// and a surrogate writes a key that no well-formed text can match.
/**
 * @return list<int>
 */
function separatorCodePoints(mixed $labels): array
{
    if (!\is_array($labels) || !\array_is_list($labels) || $labels === []) {
        throw new RuntimeException('separators must be a list with at least one label.');
    }

    $codePoints = \array_map(static function (mixed $label): int {
        if (!\is_string($label) || \preg_match('/\AU\+([0-9A-F]{4,6})\z/', $label, $hex) !== 1) {
            throw new RuntimeException(
                FORMAT_FILE . ': each separator must be written as U+ and 4 to 6 hex digits, '
                . 'but it is ' . \json_encode($label, JSON_THROW_ON_ERROR) . '.',
            );
        }
        $codePoint = \intval($hex[1], 16);
        $isSurrogate = $codePoint >= 0xD800 && $codePoint <= 0xDFFF;
        if ($codePoint > 0x10FFFF || $isSurrogate) {
            throw new RuntimeException(
                FORMAT_FILE . ": the separator {$label} must be a code point from U+0000 to "
                . 'U+10FFFF that is not a surrogate.',
            );
        }

        return $codePoint;
    }, $labels);
    // Two labels can name one code point, such as U+002D and U+00002D.
    $labelsOfCodePoints = \array_map(
        static fn(int $codePoint): string => \sprintf('U+%04X', $codePoint),
        $codePoints,
    );
    rejectRepeats($labelsOfCodePoints, FORMAT_FILE, 'separator');

    return $codePoints;
}

/**
 * @param array<mixed> $format
 * @param list<int> $separators
 */
function specData(string $root, array $format, array $separators): string
{
    $precision = readJson("{$root}/" . PRECISION_FILE);
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
    $header = fileHeader('spec/data', GATEPOST_TAGS);

    return classFile($header, $summary, 'SpecData', $constants);
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
    $states = readJson("{$root}/" . STATES_FILE)['states'] ?? null;
    $codes = [];
    $entries = [];
    foreach (objects($states, 'states') as $state) {
        $code = text($state['code'] ?? null, 'state code');
        $codes[] = $code;
        $name = quoted(text($state['name'] ?? null, 'state name'));
        $entries[] = ENTRY . quoted($code) . " => {$name},";
    }
    rejectRepeats($codes, STATES_FILE, 'state code');

    return $entries;
}

/**
 * @return list<string>
 */
function segmentEntries(mixed $segments): array
{
    $entries = [];
    $names = [];
    $start = 0;
    foreach (objects($segments, 'segments') as $segment) {
        $end = $start + wholeNumber($segment['length'] ?? null, 'segment length');
        $minimum = $segment['minimum'] ?? null;
        $name = choice($segment['name'] ?? null, PRECISIONS, 'segment name');
        $names[] = $name;
        $entries[] = ENTRY . \sprintf(
            "%s => ['start' => %d, 'length' => %d, 'end' => %d, "
            . "'characters' => %s, 'minimum' => %s],",
            quoted($name),
            $start,
            $end - $start,
            $end,
            quoted(choice($segment['characters'] ?? null, CHARACTER_RULES, 'segment characters')),
            $minimum === null ? 'null' : wholeNumber($minimum, 'segment minimum'),
        );
        $start = $end;
    }
    rejectRepeats($names, FORMAT_FILE, 'segment name');

    return $entries;
}

/**
 * @return list<string>
 */
function thresholdEntries(mixed $thresholds): array
{
    $entries = [];
    $previous = null;
    foreach (objects($thresholds, 'thresholds') as $threshold) {
        $limit = $threshold['maxAccuracyM'] ?? null;
        if (!\is_int($limit) && !\is_float($limit)) {
            throw new RuntimeException('maxAccuracyM must be a number.');
        }
        // precisionForAccuracy returns the first limit that covers an accuracy, so a limit that
        // does not rise above the one before it would never apply.
        if ($previous !== null && $limit <= $previous) {
            throw new RuntimeException(
                PRECISION_FILE . ": the limit {$limit} must be above the limit {$previous}. "
                . 'List the limits from the lowest to the highest.',
            );
        }
        $previous = $limit;
        $precision = choice($threshold['precision'] ?? null, PRECISIONS, 'threshold precision');
        $entries[] = ENTRY . \sprintf(
            "['maxAccuracyM' => %s, 'precision' => %s],",
            \json_encode($limit, JSON_THROW_ON_ERROR),
            quoted($precision),
        );
    }

    return $entries;
}

/**
 * @param array<mixed> $format
 * @param 'digits'|'letters' $kind
 *
 * @return list<string>
 */
function fixes(array $format, string $kind): array
{
    $suggestions = $format['suggestions'] ?? null;
    $fixes = \is_array($suggestions) ? ($suggestions[$kind] ?? null) : null;
    if (!\is_array($fixes) || $fixes === []) {
        throw new RuntimeException(
            FORMAT_FILE . ": suggestions.{$kind} must be an object with at least one fix, "
            . 'but it is ' . \json_encode($fixes, JSON_THROW_ON_ERROR) . '.',
        );
    }
    [$changed, $written] = FIX_CHARACTERS[$kind];
    $entries = [];
    foreach ($fixes as $from => $to) {
        // PHP turns the key "0" into the integer 0, so the cast restores the text. At run time
        // the key is the integer 0 again, so code that reads the table must use strtr() or cast
        // the keys.
        $from = text((string) $from, 'fix');
        $to = text($to, 'fix');
        $isFix = \preg_match("/\A{$changed}\z/", $from) === 1
            && \preg_match("/\A{$written}\z/", $to) === 1;
        if (!$isFix) {
            throw new RuntimeException(
                FORMAT_FILE . ": suggestions.{$kind} has the fix {$from} => {$to}. "
                . "A key must match {$changed} and a value must match {$written}.",
            );
        }
        $entries[] = ENTRY . quoted($from) . ' => ' . quoted($to) . ',';
    }

    return $entries;
}
