<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Composer\Semver\Semver;
use Gatepost\Postcode\Internal\SpecData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DOC-3 says that CI runs each code example of a README. A reader who copies an example must see
 * the output that the README shows, so each test runs one example and compares its output.
 */
final class ReadmeTest extends TestCase
{
    /**
     * The heading of each section that has a PHP example, and the text that the example prints.
     *
     * @return array<string, array{string, string}>
     */
    public static function examples(): array
    {
        return [
            'the quickstart' => ['Quickstart', 'EK-01-A03-FK-01'],
            'the input limit' => ['Limit the input', "ok\nbad_length\n"],
        ];
    }

    #[Test]
    #[DataProvider('examples')]
    public function runsEachPhpExampleAndPrintsItsResult(string $heading, string $printed): void
    {
        $code = self::phpExample(self::section($heading));
        $file = \tempnam(\sys_get_temp_dir(), 'readme-example');
        self::assertIsString($file);

        // Each statement after tempnam() sits in the try block, so a failure removes the file.
        try {
            \file_put_contents($file, $code);
            $this->expectOutputString($printed);
            // A closure keeps the variables of the example away from the variables of the test.
            (static function () use ($file): void {
                require $file;
            })();
        } finally {
            \unlink($file);
        }
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function constraintsAndVersions(): array
    {
        return [
            'an alpha in the range, with the flag' => ['^0.1@alpha', '0.1.0-alpha.0', true],
            'a stable release in the range' => ['^0.1@alpha', '0.1.3', true],
            'an alpha of the next minor version' => ['^0.1@alpha', '0.2.0-alpha.0', false],
            'an alpha, with no flag' => ['^0.1', '0.1.0-alpha.0', false],
            'a stable release, with no flag' => ['^0.1', '0.1.1', true],
            'a release below the range' => ['^1.0', '0.9.0', false],
        ];
    }

    #[Test]
    #[DataProvider('constraintsAndVersions')]
    public function readsWhichVersionsAConstraintAllows(
        string $constraint,
        string $version,
        bool $allowed,
    ): void {
        self::assertSame($allowed, self::constraintAllows($constraint, $version));
    }

    #[Test]
    public function listsEachPhpExampleOfTheReadmeInTheTest(): void
    {
        $examples = \preg_match_all('/^```php$/m', self::repoFile('README.md'));

        self::assertSame(
            \count(self::examples()),
            $examples,
            'Add each PHP example of the README to examples(), so that the test runs it.',
        );
    }

    #[Test]
    public function statesTheInputLimitOfTheSpec(): void
    {
        $section = self::section('Limit the input');

        self::assertSame(1, \preg_match('/at most (\d+) code points/', $section, $limit));
        self::assertSame(SpecData::MAX_INPUT_CODE_POINTS, (int) $limit[1]);
    }

    #[Test]
    public function installsWithAConstraintThatAllowsTheNewestRelease(): void
    {
        $found = \preg_match('/^## (\S+) - /m', self::repoFile('CHANGELOG.md'), $newest);
        self::assertSame(1, $found, 'The changelog has no release.');
        $pattern = '/^composer require gatepost\/postcode(?::(\S+))?$/m';
        $found = \preg_match($pattern, self::section('Install'), $command);
        self::assertSame(1, $found, 'The Install section has no composer require line.');
        $constraint = $command[1] ?? '*';

        self::assertTrue(
            self::constraintAllows($constraint, $newest[1]),
            "The install constraint {$constraint} does not allow the newest release {$newest[1]}.",
        );
    }

    private static function repoFile(string $name): string
    {
        $contents = \file_get_contents(\dirname(__DIR__, 2) . '/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    // Composer installs a pre-release only when the constraint names its stability, such as @alpha.
    // The version must also lie in the range of the constraint.
    private static function constraintAllows(string $constraint, string $version): bool
    {
        return \str_contains($constraint, self::stabilityFlag($version))
            && Semver::satisfies($version, $constraint);
    }

    private static function stabilityFlag(string $version): string
    {
        return \preg_match('/-(alpha|beta|rc)\b/i', $version, $label) === 1 ? '@' . $label[1] : '';
    }

    /**
     * The text of a section, from its heading to the next heading.
     */
    private static function section(string $heading): string
    {
        $pattern = '/^#{2,3} ' . \preg_quote($heading, '/') . '\n(.*?)(?=^#|\z)/ms';
        $found = \preg_match($pattern, self::repoFile('README.md'), $section);

        self::assertSame(1, $found, "The README has no section {$heading}.");

        return $section[1];
    }

    private static function phpExample(string $section): string
    {
        $found = \preg_match('/^```php\n(.*?)^```$/ms', $section, $example);

        self::assertSame(1, $found, 'The section has no PHP example.');

        return $example[1];
    }
}
