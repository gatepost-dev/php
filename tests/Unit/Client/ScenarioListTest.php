<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Tests\Contract\Scenario;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract suite needs the mock server, so composer check does not run it. This test runs
 * in composer check. It reads every scenario, and it checks which ones the suite leaves out:
 * the scenarios that start their calls at the same time, which a synchronous client cannot run.
 */
final class ScenarioListTest extends TestCase
{
    #[Test]
    public function readsEveryScenarioFileOfTheSpec(): void
    {
        $files = \glob(\dirname(__DIR__, 3) . '/spec/contract/*.json');
        self::assertIsArray($files);

        self::assertCount(\count($files), Scenario::all());
    }

    #[Test]
    public function runsEveryScenarioWhoseCallsDoNotRunInParallel(): void
    {
        $runnable = Scenario::runnable();

        self::assertNotSame([], $runnable);
        self::assertSame(
            \count(Scenario::all()),
            \count($runnable) + \count(Scenario::parallel()),
        );
        foreach ($runnable as $scenario) {
            self::assertNotSame('parallel', $scenario->order, $scenario->id);
        }
    }

    #[Test]
    public function leavesOutOnlyScenariosWithSeveralCalls(): void
    {
        foreach (Scenario::parallel() as $scenario) {
            self::assertGreaterThan(1, \count($scenario->calls), $scenario->id);
        }
    }

    #[Test]
    public function runsNoScenarioThatNeedsRequestsInFlightTogether(): void
    {
        foreach (Scenario::runnable() as $scenario) {
            self::assertLessThanOrEqual(1, $scenario->expect['maxInFlight'] ?? 1, $scenario->id);
        }
    }
}
