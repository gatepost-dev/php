<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client\Internal;

/**
 * The clock, the waits and the random part of each wait. The client uses SystemTimer. Tests use
 * a fake, so that they need no real clock and no sleep (T-5, API-10).
 *
 * @internal
 */
interface Timer
{
    /**
     * The time in milliseconds since the Unix epoch.
     */
    public function nowMs(): float;

    /**
     * Blocks for a number of milliseconds.
     */
    public function wait(int $ms): void;

    /**
     * A random number of milliseconds, from 0 to $maxMs.
     */
    public function jitterMs(int $maxMs): int;
}
