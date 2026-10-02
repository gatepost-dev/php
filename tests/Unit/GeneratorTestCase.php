<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Closure;
use FilesystemIterator;
use Gatepost\Postcode\Tests\ScriptRun;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The harness for the tests of the generator. The generator takes its root from __DIR__, so each
 * test copies scripts/ and the small data set in tests/Fixtures/generator into a temporary root
 * and runs the copy there. The generator also reads the UnicodeData.txt that composer generate
 * cached in build/unicode, so each test copies that file too. No test uses the network.
 */
abstract class GeneratorTestCase extends TestCase
{
    protected const SPEC_DATA = 'src/Internal/SpecData.php';
    protected const NFKC_TABLE = 'src/Internal/NfkcTable.php';
    protected const GENERATED = [self::SPEC_DATA, self::NFKC_TABLE];
    // grammar.md names Unicode 17.0 as the NFKC baseline. A table must come from that version.
    protected const UNICODE_VERSION = '17.0.0';
    protected const UNICODE_DATA = 'build/unicode/UnicodeData-' . self::UNICODE_VERSION . '.txt';

    protected string $root;

    protected function setUp(): void
    {
        $root = \tempnam(\sys_get_temp_dir(), 'gatepost-generator-');
        self::assertIsString($root);
        \unlink($root);
        $this->root = $root;
        self::copyFiles(\dirname(__DIR__, 2) . '/scripts/*.php', "{$root}/scripts");
        self::copyFiles(
            \dirname(__DIR__) . '/Fixtures/generator/spec/data/*.json',
            "{$root}/spec/data",
        );
        $cached = \dirname(__DIR__, 2) . '/' . self::UNICODE_DATA;
        self::assertFileExists(
            $cached,
            'The cached UnicodeData.txt is missing. Run composer generate first. It downloads '
            . 'the file into build/unicode.',
        );
        self::copyFiles($cached, "{$root}/build/unicode");
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }
        \rmdir($this->root);
    }

    protected function generate(string ...$arguments): ScriptRun
    {
        return ScriptRun::of(
            \PHP_BINARY,
            '-d',
            'display_errors=1',
            "{$this->root}/scripts/generate-data.php",
            ...$arguments,
        );
    }

    /**
     * @return array<string, string> The written classes by path. A class that the root lacks has
     *                               no entry.
     */
    protected function generatedClasses(): array
    {
        $classes = [];
        foreach (self::GENERATED as $path) {
            $file = "{$this->root}/{$path}";
            if (!\is_file($file)) {
                continue;
            }
            $class = \file_get_contents($file);
            self::assertIsString($class);
            $classes[$path] = $class;
        }

        return $classes;
    }

    /**
     * @return Closure(string): void Changes text in a file under the temporary root.
     */
    protected static function edit(string $file, string $search, string $replacement): Closure
    {
        return static function (string $root) use ($file, $search, $replacement): void {
            $text = \file_get_contents("{$root}/{$file}");
            self::assertIsString($text);
            self::assertStringContainsString($search, $text, "{$file} lacks the text.");
            self::assertNotFalse(
                \file_put_contents("{$root}/{$file}", \str_replace($search, $replacement, $text)),
            );
        };
    }

    /**
     * @return Closure(string): void Removes a file under the temporary root.
     */
    protected static function delete(string $file): Closure
    {
        return static function (string $root) use ($file): void {
            \unlink("{$root}/{$file}");
        };
    }

    private static function copyFiles(string $pattern, string $folder): void
    {
        $files = \glob($pattern);
        self::assertIsArray($files);
        self::assertNotSame([], $files, "{$pattern} matches no file.");
        \mkdir($folder, 0o777, true);
        foreach ($files as $file) {
            \copy($file, "{$folder}/" . \basename($file));
        }
    }
}
