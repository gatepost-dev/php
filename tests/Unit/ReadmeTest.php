<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Composer\Semver\Semver;
use Gatepost\Postcode\Internal\SpecData;
use Gatepost\Postcode\Tests\Contract\ReadmeExampleTest;
use Gatepost\Postcode\Tests\Readme;
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
        $this->expectOutputString($printed);

        Readme::run(Readme::phpExample(Readme::section($heading)));
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

    // The example of the client calls the gateway, so the contract suite runs it against the
    // mock server.
    #[Test]
    public function listsEachPhpExampleOfTheReadmeInATest(): void
    {
        $examples = \preg_match_all('/^```php$/m', Readme::text());

        self::assertSame(
            \count(self::examples()) + \count(ReadmeExampleTest::examples()),
            $examples,
            'Add each PHP example of the README to examples() of a test, so that the test runs it.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clientExampleSections(): array
    {
        return [
            'the check of a postcode' => ['Check a postcode with the gateway'],
            'the search for a place' => ['Find a place and complete a postcode'],
        ];
    }

    // One PSR-18 transport holds one timeout. The client reads a failed attempt by timeoutMs, so
    // the example must pass the timeout of its transport as timeoutMs too.
    #[Test]
    #[DataProvider('clientExampleSections')]
    public function givesTheTransportTimeoutAndTimeoutMsTheSameValue(string $heading): void
    {
        $example = Readme::phpExample(Readme::section($heading));

        self::assertSame(1, \preg_match("/'timeout' => (\d+)/", $example, $transport));
        self::assertSame(1, \preg_match('/timeoutMs: (\d+),/', $example, $client));
        self::assertSame((int) $transport[1] * 1000, (int) $client[1]);
    }

    #[Test]
    public function statesTheInputLimitOfTheSpec(): void
    {
        $section = Readme::section('Limit the input');

        self::assertSame(1, \preg_match('/at most (\d+) code points/', $section, $limit));
        self::assertSame(SpecData::MAX_INPUT_CODE_POINTS, (int) $limit[1]);
    }

    #[Test]
    public function installsWithAConstraintThatAllowsTheNewestRelease(): void
    {
        $changelog = \file_get_contents(\dirname(__DIR__, 2) . '/CHANGELOG.md');
        self::assertIsString($changelog);
        $found = \preg_match('/^## (\S+) - /m', $changelog, $newest);
        self::assertSame(1, $found, 'The changelog has no release.');
        $pattern = '/^composer require gatepost\/postcode(?::(\S+))?$/m';
        $found = \preg_match($pattern, Readme::section('Install'), $command);
        self::assertSame(1, $found, 'The Install section has no composer require line.');
        $constraint = $command[1] ?? '*';

        self::assertTrue(
            self::constraintAllows($constraint, $newest[1]),
            "The install constraint {$constraint} does not allow the newest release {$newest[1]}.",
        );
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
}
