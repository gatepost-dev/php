<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Tests\ScriptRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The T-7 floor rests on scripts/check-coverage.php, so these tests run the script on the small
 * reports in tests/Fixtures/coverage.
 */
final class CheckCoverageTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedReports(): array
    {
        return [
            'branch coverage at the floor' => [
                'at-floor.xml',
                'Branch coverage is 95.00 % (19 of 20).',
            ],
            'a report with no code' => [
                'no-code.xml',
                'The report has no branches, so there is nothing to cover.',
            ],
            'a class of constants next to covered code' => [
                'constants-class.xml',
                'Branch coverage is 95.00 % (19 of 20).',
            ],
        ];
    }

    #[Test]
    #[DataProvider('acceptedReports')]
    public function acceptsAReport(string $report, string $summary): void
    {
        $run = self::check($report);

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame($summary, $run->output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedReports(): array
    {
        return [
            'branch coverage below the floor' => [
                'below-floor.xml',
                'Branch coverage is 94.00 % (47 of 50). The floor for core code is 95 %.',
            ],
            'lines and a branch count of zero' => ['no-branch-data.xml', 'no branch data'],
            'lines and no branch attributes' => ['no-branch-attributes.xml', 'no branch data'],
            'a class that no test runs' => ['unrun-class.xml', 'Gatepost\Postcode\Neglected'],
            'a class with one path that no test runs' => [
                'unrun-straight-class.xml',
                'Gatepost\Postcode\Straight',
            ],
        ];
    }

    #[Test]
    #[DataProvider('rejectedReports')]
    public function rejectsAReport(string $report, string $reason): void
    {
        $run = self::check($report);

        self::assertSame(1, $run->exitCode);
        self::assertStringContainsString($reason, $run->output);
    }

    private static function check(string $report): ScriptRun
    {
        return ScriptRun::of(
            \PHP_BINARY,
            'scripts/check-coverage.php',
            \dirname(__DIR__) . '/Fixtures/coverage/' . $report,
        );
    }
}
