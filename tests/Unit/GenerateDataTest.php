<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Closure;
use FilesystemIterator;
use Gatepost\Postcode\Tests\ScriptRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * composer check runs the generator on the real data only, so it never meets a stale class, a bad
 * argument or a broken data file. The generator takes its root from __DIR__, so each test copies
 * scripts/ and the small data set in tests/Fixtures/generator into a temporary root and runs the
 * copy there. The generator also reads the UnicodeData.txt that composer generate cached in
 * build/unicode, so each test copies that file too. No test uses the network. A data provider
 * row holds a closure that changes the files under that root.
 */
final class GenerateDataTest extends TestCase
{
    private const SPEC_DATA = 'src/Internal/SpecData.php';
    private const NFKC_TABLE = 'src/Internal/NfkcTable.php';
    private const GENERATED = [self::SPEC_DATA, self::NFKC_TABLE];
    // grammar.md names Unicode 17.0 as the NFKC baseline. A table must come from that version.
    private const UNICODE_VERSION = '17.0.0';
    private const UNICODE_DATA = 'build/unicode/UnicodeData-' . self::UNICODE_VERSION . '.txt';

    private string $root;

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

    #[Test]
    public function writesTheClassesFromTheDataThatTheCheckAccepts(): void
    {
        $written = $this->generate();
        $checked = $this->generate('--check');

        self::assertSame(0, $written->exitCode, $written->output);
        self::assertSame('', $written->output);
        $classes = $this->generatedClasses();
        self::assertSame(self::GENERATED, \array_keys($classes));
        $specData = $classes[self::SPEC_DATA];
        self::assertStringContainsString("'EK' => 'Ekiti',", $specData);
        self::assertStringContainsString('LEGACY_PATTERN = \'/^[0-9]{6}$/D\';', $specData);
        self::assertStringNotContainsString('NG-EK', $specData);
        $table = $classes[self::NFKC_TABLE];
        $version = self::UNICODE_VERSION;
        self::assertStringContainsString("UNICODE_VERSION = '{$version}';", $table);
        self::assertStringContainsString('"\u{FF21}" => "A",', $table);
        self::assertStringNotContainsString('"\u{00B5}" =>', $table);
        self::assertSame(0, $checked->exitCode, $checked->output);
        self::assertSame('', $checked->output);
    }

    /**
     * Each row makes a written class stale or removes it, and gives the path that the check must
     * name.
     *
     * @return array<string, array{Closure(string): void, string}>
     */
    public static function staleClasses(): array
    {
        return [
            'a value that someone edited in the class' => [
                self::edit(
                    self::SPEC_DATA,
                    'MAX_INPUT_CODE_POINTS = 64;',
                    'MAX_INPUT_CODE_POINTS = 65;',
                ),
                self::SPEC_DATA,
            ],
            'a limit that the spec changed after the last run' => [
                self::edit('spec/data/precision.json', '"maxAccuracyM": 8', '"maxAccuracyM": 9'),
                self::SPEC_DATA,
            ],
            'a class that someone deleted' => [self::delete(self::SPEC_DATA), self::SPEC_DATA],
            'a form that someone edited in the table' => [
                self::edit(self::NFKC_TABLE, '"\u{00AA}" => "a",', '"\u{00AA}" => "b",'),
                self::NFKC_TABLE,
            ],
        ];
    }

    /**
     * @param Closure(string): void $makeStale
     */
    #[Test]
    #[DataProvider('staleClasses')]
    public function failsTheCheckAndLeavesTheFilesAsTheyAre(
        Closure $makeStale,
        string $stalePath,
    ): void {
        $this->generate();
        $makeStale($this->root);
        $before = $this->generatedClasses();

        $run = $this->generate('--check');

        self::assertSame(1, $run->exitCode, $run->output);
        self::assertSame("{$stalePath} is out of date. Run composer generate.", $run->output);
        self::assertSame($before, $this->generatedClasses());
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function unknownArguments(): array
    {
        return [
            'an unknown argument' => [['--bogus']],
            'an unknown argument next to --check' => [['--check', '--bogus']],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('unknownArguments')]
    public function rejectsAnUnknownArgument(array $arguments): void
    {
        $run = $this->generate(...$arguments);

        self::assertSame(2, $run->exitCode, $run->output);
        self::assertStringContainsString('Unknown argument --bogus', $run->output);
        self::assertSame([], $this->generatedClasses());
    }

    /**
     * @return array<string, array{Closure(string): void, string}> Each row breaks the data and
     *                                                              gives the reason that must
     *                                                              show.
     */
    public static function brokenData(): array
    {
        $format = 'spec/data/format.json';
        $precision = 'spec/data/precision.json';
        $digits = '"digits": { "O": "0", "I": "1", "L": "1" }';
        $letters = '"letters": { "0": "O", "1": "I" }';
        $notCodePoint = 'must be a code point from U+0000';

        return [
            'format.json with version 2' => [
                self::edit($format, '"version": 1', '"version": 2'),
                'spec/data/format.json must hold an object with version 1',
            ],
            'precision.json with version 2' => [
                self::edit($precision, '"version": 1', '"version": 2'),
                'spec/data/precision.json must hold an object with version 1',
            ],
            'states.json with version 2' => [
                self::edit('spec/data/states.json', '"version": 1', '"version": 2'),
                'spec/data/states.json must hold an object with version 1',
            ],
            'a missing data file' => [
                self::delete('spec/data/states.json'),
                'Cannot read',
            ],
            'a fix table that is a list' => [
                self::edit($format, $digits, '"digits": ["O", "I", "L"]'),
                'spec/data/format.json: suggestions.digits has the fix 0 => O',
            ],
            'a fix table that writes a letter in a digit segment' => [
                self::edit($format, '"O": "0", "I": "1"', '"O": "A", "I": "1"'),
                'spec/data/format.json: suggestions.digits has the fix O => A',
            ],
            'a fix table that writes a digit in a letter segment' => [
                self::edit($format, $letters, '"letters": { "O": "0" }'),
                'spec/data/format.json: suggestions.letters has the fix O => 0',
            ],
            'a fix table that is not an object' => [
                self::edit($format, $digits, '"digits": "none"'),
                'spec/data/format.json: suggestions.digits must be an object with at least one '
                . 'fix, but it is "none"',
            ],
            'a fix table with no fix' => [
                self::edit($format, $digits, '"digits": {}'),
                'spec/data/format.json: suggestions.digits must be an object with at least one '
                . 'fix, but it is []',
            ],
            'limits that fall' => [
                self::edit($precision, '"maxAccuracyM": 20', '"maxAccuracyM": 5'),
                'spec/data/precision.json: the limit 5 must be above the limit 8',
            ],
            'limits that repeat' => [
                self::edit($precision, '"maxAccuracyM": 20', '"maxAccuracyM": 8'),
                'spec/data/precision.json: the limit 8 must be above the limit 8',
            ],
            'a separator above U+10FFFF' => [
                self::edit($format, '"U+002D"', '"U+110000"'),
                "spec/data/format.json: the separator U+110000 {$notCodePoint}",
            ],
            'the lowest surrogate as a separator' => [
                self::edit($format, '"U+002D"', '"U+D800"'),
                "spec/data/format.json: the separator U+D800 {$notCodePoint}",
            ],
            'the highest surrogate as a separator' => [
                self::edit($format, '"U+002D"', '"U+DFFF"'),
                "spec/data/format.json: the separator U+DFFF {$notCodePoint}",
            ],
            'a separator that is not written as a code point' => [
                self::edit($format, '"U+002D"', '"0x0009"'),
                'spec/data/format.json: each separator must be written as U+ and 4 to 6 hex '
                . 'digits, but it is "0x0009"',
            ],
            'a cached UnicodeData.txt that someone changed' => [
                self::edit(
                    self::UNICODE_DATA,
                    'FF21;FULLWIDTH LATIN CAPITAL LETTER A;Lu;0;L;<wide> 0041;',
                    'FF21;FULLWIDTH LATIN CAPITAL LETTER A;Lu;0;L;<wide> 0042;',
                ),
                'does not match its SHA-256 hash',
            ],
        ];
    }

    /**
     * @param Closure(string): void $breakData
     */
    #[Test]
    #[DataProvider('brokenData')]
    public function stopsOnBrokenDataWithAClearReasonAndKeepsTheOldClasses(
        Closure $breakData,
        string $reason,
    ): void {
        $this->generate();
        $old = $this->generatedClasses();
        $breakData($this->root);

        $run = $this->generate();

        self::assertNotSame(0, $run->exitCode, $run->output);
        self::assertStringContainsString($reason, $run->output);
        self::assertStringNotContainsString('Warning', $run->output);
        self::assertSame($old, $this->generatedClasses());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function edgeSeparators(): array
    {
        return [
            'the lowest code point' => ['U+0000'],
            'the code point before the surrogates' => ['U+D7FF'],
            'the code point after the surrogates' => ['U+E000'],
            'the highest code point' => ['U+10FFFF'],
        ];
    }

    #[Test]
    #[DataProvider('edgeSeparators')]
    public function writesAClassThatParsesForAnEdgeCodePoint(string $label): void
    {
        self::edit('spec/data/format.json', '"U+002D"', "\"{$label}\"")($this->root);

        $run = $this->generate();

        self::assertSame(0, $run->exitCode, $run->output);
        $class = $this->generatedClasses()[self::SPEC_DATA] ?? null;
        self::assertNotNull($class);
        // PHP throws a ParseError here when the class has a syntax error.
        self::assertNotSame([], \token_get_all($class, TOKEN_PARSE));
    }

    private function generate(string ...$arguments): ScriptRun
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
    private function generatedClasses(): array
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
    private static function edit(string $file, string $search, string $replacement): Closure
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
    private static function delete(string $file): Closure
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
