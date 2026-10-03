<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\Internal\SystemTimer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one class that reads the real clock. These tests read it too, and they never wait.
 */
final class SystemTimerTest extends TestCase
{
    #[Test]
    public function readsTheClockInMillisecondsSinceTheEpoch(): void
    {
        $before = \time() * 1000;

        $nowMs = (new SystemTimer())->nowMs();

        self::assertGreaterThanOrEqual($before, $nowMs);
        self::assertLessThan($before + 2000, $nowMs);
    }

    #[Test]
    public function returnsAtOnceFromAWaitOfZero(): void
    {
        $timer = new SystemTimer();
        $before = $timer->nowMs();

        $timer->wait(0);

        self::assertLessThan($before + 50, $timer->nowMs());
    }

    #[Test]
    public function keepsEachRandomPartOfAWaitWithinItsLimit(): void
    {
        $timer = new SystemTimer();
        $parts = [];
        for ($draw = 0; $draw < 200; ++$draw) {
            $parts[$timer->jitterMs(2)] = true;
        }
        \ksort($parts);

        self::assertSame([0, 1, 2], \array_keys($parts));
    }
}
