<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests;

use RuntimeException;

/**
 * Reads README.md and runs its PHP examples. ReadmeTest runs the examples that need no network,
 * and the contract suite runs the client example against the mock server.
 */
final class Readme
{
    /**
     * The text of a section, from its heading to the next heading.
     */
    public static function section(string $heading): string
    {
        $pattern = '/^#{2,3} ' . \preg_quote($heading, '/') . '\n(.*?)(?=^#|\z)/ms';
        if (\preg_match($pattern, self::text(), $section) !== 1) {
            throw new RuntimeException("The README has no section {$heading}.");
        }

        return $section[1];
    }

    /**
     * The first PHP example of a section.
     */
    public static function phpExample(string $section): string
    {
        if (\preg_match('/^```php\n(.*?)^```$/ms', $section, $example) !== 1) {
            throw new RuntimeException('The section has no PHP example.');
        }

        return $example[1];
    }

    /**
     * Runs an example as its own file. A closure keeps the variables of the example away from
     * the variables of the test.
     */
    public static function run(string $code): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'readme-example');
        if ($file === false) {
            throw new RuntimeException('Cannot make a temporary file for the example.');
        }
        // Each statement after tempnam() sits in the try block, so a failure removes the file.
        try {
            \file_put_contents($file, $code);
            (static function () use ($file): void {
                require $file;
            })();
        } finally {
            \unlink($file);
        }
    }

    /**
     * The whole README.
     */
    public static function text(): string
    {
        $contents = \file_get_contents(\dirname(__DIR__) . '/README.md');
        if ($contents === false) {
            throw new RuntimeException('Cannot read README.md.');
        }

        return $contents;
    }
}
