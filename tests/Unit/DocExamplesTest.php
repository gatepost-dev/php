<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Closure;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The docs site holds the API reference.
 * DOC-3 asks that CI runs each code example. ReadmeTest runs the examples of the README. This test
 * runs the php examples of the doc comments, and it compares each result that an example shows.
 *
 * A line that ends with a comment shows a result. `echo $postcode->redact(); // EK-01-A03-FK-**`
 * shows the text that the line prints, and `$district->contains($building); // true` shows a value.
 * The doc comment of a method may use the object of that method with no line that makes it, so each
 * example runs after the lines of SETUP. The client in SETUP answers from the synthetic fixtures of
 * the spec, with no network.
 */
final class DocExamplesTest extends TestCase
{
    private const SETUP = [
        '$input = \'ek 01 a03 fk 01\';',
        '$postcode = Postcode::parse(\'EK-01-A03-FK-01\')->value;',
        '$building = $postcode;',
        '$district = Postcode::parse(\'EK-01-A03\', allowPartial: true)->value;',
        '$client = \\Gatepost\\Postcode\\Tests\\Unit\\Client\\FixtureGateway::client();',
    ];

    private const COMMENT_MARK = '/^\s*(?:\/\*\*|\*\/|\*)\s?/';
    private const ECHO_LINE = '/^(\s*)echo (.+); \/\/ (.+)$/';
    private const VALUE = '(?:true|false|null|-?\d+(?:\.\d+)?|\'[^\']*\'|[A-Z]\w*::\w+)';
    private const VALUE_LINE = '/^(\s*)(.+); \/\/ (' . self::VALUE . ')$/';
    // A comment that starts like a value, but that VALUE_LINE cannot read, would leave its result
    // unchecked. The test fails on such a line, so that the author writes a form that it reads.
    private const LOOKS_LIKE_VALUE = '/; \/\/ (?:true|false|null|-?\d|[\'"\[{]|[A-Z]\w*::)/';

    /**
     * @return array<string, array{string}> Each example, by its file and its place in the file.
     */
    public static function examples(): array
    {
        $examples = [];
        foreach (self::docComments() as $file => $comments) {
            $blocks = [];
            foreach ($comments as $comment) {
                \preg_match_all('/^```php\n(.*?)^```$/ms', $comment, $found);
                $blocks = [...$blocks, ...$found[1]];
            }
            foreach ($blocks as $number => $block) {
                $examples[$file . ' example ' . ($number + 1)] = [$block];
            }
        }

        return $examples;
    }

    #[Test]
    #[DataProvider('examples')]
    public function runsEachExampleAndComparesEachResultItShows(string $example): void
    {
        self::assertSame(
            [],
            self::unreadableResults($example),
            'The example shows a result in a form that this test cannot read.',
        );
        $checked = 0;
        $compare = function (mixed $actual, mixed $expected) use (&$checked): void {
            self::assertSame($expected, $actual);
            ++$checked;
        };
        $file = \tempnam(\sys_get_temp_dir(), 'doc-example');
        self::assertIsString($file);

        // An example may print, and the lines that show a result print nothing, so the output
        // of the example is dropped.
        \ob_start();
        try {
            \file_put_contents($file, self::module($example));
            $run = require $file;
            self::assertInstanceOf(Closure::class, $run);
            $run($compare);
        } finally {
            \ob_end_clean();
            \unlink($file);
        }

        self::assertSame(
            self::countResults($example),
            $checked,
            'The example skipped a result that it shows. A guard, such as an if, can skip a line.',
        );
    }

    /**
     * The doc comments of each PHP file in src, without their comment marks.
     *
     * @return array<string, list<string>>
     */
    private static function docComments(): array
    {
        $source = \dirname(__DIR__, 2) . '/src';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        $comments = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = \file_get_contents($file->getPathname());
            self::assertIsString($code);
            $name = \substr($file->getPathname(), \strlen($source) + 1);
            foreach (\token_get_all($code) as $token) {
                if (\is_array($token) && $token[0] === \T_DOC_COMMENT) {
                    $comments[$name][] = self::withoutMarks($token[1]);
                }
            }
        }
        \ksort($comments);

        return $comments;
    }

    // A code fence starts a line only after the asterisks are gone.
    private static function withoutMarks(string $comment): string
    {
        $lines = [];
        foreach (\explode("\n", $comment) as $line) {
            $lines[] = \preg_replace(self::COMMENT_MARK, '', $line) ?? $line;
        }

        return \implode("\n", $lines);
    }

    /**
     * Turns an example into a file that returns a closure. Each line that shows a result calls
     * $shown with the actual result and the shown one.
     */
    private static function module(string $example): string
    {
        $lines = \array_map(static function (string $line): string {
            if (\preg_match(self::ECHO_LINE, $line, $echo) === 1) {
                $printed = \var_export($echo[3], true);

                return $echo[1] . '$shown((string) (' . $echo[2] . '), ' . $printed . ');';
            }
            if (\preg_match(self::VALUE_LINE, $line, $value) === 1) {
                return $value[1] . '$shown(' . $value[2] . ', ' . $value[3] . ');';
            }

            return $line;
        }, \explode("\n", $example));

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Gatepost\\Postcode;\n\n"
            . "use Gatepost\\Postcode\\Client\\PostcodeClient;\n\n"
            . 'return static function (\Closure $shown): void {' . "\n"
            . \implode("\n", [...self::SETUP, ...$lines]) . "\n};\n";
    }

    private static function countResults(string $example): int
    {
        $results = \array_filter(
            \explode("\n", $example),
            static fn(string $line): bool => \preg_match(self::ECHO_LINE, $line) === 1
                || \preg_match(self::VALUE_LINE, $line) === 1,
        );

        return \count($results);
    }

    /**
     * @return list<string>
     */
    private static function unreadableResults(string $example): array
    {
        return \array_values(\array_filter(
            \explode("\n", $example),
            static fn(string $line): bool => \preg_match(self::LOOKS_LIKE_VALUE, $line) === 1
                && \preg_match(self::ECHO_LINE, $line) !== 1
                && \preg_match(self::VALUE_LINE, $line) !== 1,
        ));
    }
}
