<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Builds NfkcTable.php for scripts/generate-data.php, which holds the helpers that this file
// calls, such as escaped(), classFile() and writeFile().

namespace Gatepost\Postcode\Scripts;

use RuntimeException;

const UNICODE_VERSION = '17.0.0';
const UNICODE_DATA_URL = 'https://www.unicode.org/Public/17.0.0/ucd/UnicodeData.txt';
const UNICODE_DATA_SHA256 = '2e1efc1dcb59c575eedf5ccae60f95229f706ee6d031835247d843c11d96470c';

// The download is kept in build/, which git ignores. The hash pins the exact file, so a
// changed or partial download fails.
function unicodeData(string $root): string
{
    $path = "{$root}/build/unicode/UnicodeData-" . UNICODE_VERSION . '.txt';
    if (!\is_file($path)) {
        $downloaded = \file_get_contents(UNICODE_DATA_URL);
        if ($downloaded === false) {
            throw new RuntimeException('Cannot download ' . UNICODE_DATA_URL);
        }
        writeFile($path, $downloaded);
    }
    $contents = \file_get_contents($path);
    if ($contents === false || \hash('sha256', $contents) !== UNICODE_DATA_SHA256) {
        throw new RuntimeException("{$path} does not match its SHA-256 hash. Delete it and retry.");
    }

    return $contents;
}

// A character goes into the table when its full compatibility decomposition holds only ASCII
// characters and separators. For such a character, the decomposition is also its NFKC form,
// because no ASCII character and no separator composes with another character.
/**
 * @param list<int> $separators
 *
 * @return array<int, list<int>>
 */
function nfkcForms(string $unicodeData, array $separators): array
{
    $mappings = [];
    foreach (\explode("\n", \trim($unicodeData)) as $line) {
        $fields = \explode(';', $line);
        $mapping = \preg_replace('/\A<[a-zA-Z]+> /', '', $fields[5] ?? '');
        if ($mapping !== null && $mapping !== '') {
            $mappings[\intval($fields[0], 16)] = \array_map(
                static fn(string $hex): int => \intval($hex, 16),
                \explode(' ', $mapping),
            );
        }
    }
    $forms = [];
    foreach (\array_keys($mappings) as $codePoint) {
        $form = decomposition($codePoint, $mappings);
        $others = \array_filter(
            $form,
            static fn(int $part): bool => $part > 0x7F && !\in_array($part, $separators, true),
        );
        if ($others === []) {
            $forms[$codePoint] = $form;
        }
    }

    return $forms;
}

/**
 * @param array<int, list<int>> $mappings
 *
 * @return list<int>
 */
function decomposition(int $codePoint, array $mappings): array
{
    if (!isset($mappings[$codePoint])) {
        return [$codePoint];
    }

    return \array_merge(...\array_map(
        static fn(int $part): array => decomposition($part, $mappings),
        $mappings[$codePoint],
    ));
}

/**
 * @param list<int> $separators
 */
function nfkcTable(string $unicodeData, array $separators): string
{
    $entries = [];
    foreach (nfkcForms($unicodeData, $separators) as $codePoint => $form) {
        $entries[] = ENTRY . escaped([$codePoint]) . ' => ' . escaped($form) . ',';
    }
    $constants = [
        ...constantLines(
            'The version of the Unicode Character Database that the table comes from.',
            'UNICODE_VERSION',
            quoted(UNICODE_VERSION),
        ),
        ...constantLines(
            'Each character whose NFKC form holds only ASCII characters and separators.',
            'FORMS',
            $entries,
        ),
    ];
    $summary = 'NFKC forms for strtr(). The table leaves out each character that no '
        . 'postcode can hold.';

    return classFile('UnicodeData.txt ' . UNICODE_VERSION, $summary, 'NfkcTable', $constants);
}
