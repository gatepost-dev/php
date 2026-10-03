<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client\Internal;

/**
 * The real clock and the real waits.
 *
 * @internal
 */
final class SystemTimer implements Timer
{
    public function nowMs(): float
    {
        return \microtime(true) * 1000;
    }

    public function wait(int $ms): void
    {
        \usleep($ms * 1000);
    }

    public function jitterMs(int $maxMs): int
    {
        return \random_int(0, $maxMs);
    }
}
