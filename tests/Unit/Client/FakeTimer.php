<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\Internal\Timer;

/**
 * A clock that moves only when a test or a wait moves it, so the tests of retries need no sleep
 * (T-5). It starts 0.6 ms after 12 Oct 2026, 09:00:00 UTC, so a wait that the client works out
 * from an HTTP date must round up to whole milliseconds.
 */
final class FakeTimer implements Timer
{
    public const START_MS = 1_791_795_600_000.6;

    /** @var list<int> */
    public array $waits = [];

    private float $nowMs = self::START_MS;

    /**
     * @param int $jitterMs The random part that each wait gets.
     */
    public function __construct(private readonly int $jitterMs = 0) {}

    public function nowMs(): float
    {
        return $this->nowMs;
    }

    public function wait(int $ms): void
    {
        $this->waits[] = $ms;
        $this->advance($ms);
    }

    public function jitterMs(int $maxMs): int
    {
        return \min($this->jitterMs, $maxMs);
    }

    public function advance(float $ms): void
    {
        $this->nowMs += $ms;
    }
}
