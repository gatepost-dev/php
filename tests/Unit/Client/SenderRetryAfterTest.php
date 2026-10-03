<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A Retry-After on a 502, 503 or 504. A valid value of 10 seconds or less sets the wait.
 */
final class SenderRetryAfterTest extends SenderTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function validRetryAfters(): array
    {
        return [
            'zero seconds' => ['0', 0],
            'four seconds' => ['4', 4000],
            'ten seconds, the longest that the client waits' => ['10', 10_000],
            'an HTTP date three seconds ahead' => ['Mon, 12 Oct 2026 09:00:03 GMT', 3000],
        ];
    }

    #[Test]
    #[DataProvider('validRetryAfters')]
    public function waitsExactlyAsLongAsAValidRetryAfterAsksOnA503(
        string $header,
        int $waitMs,
    ): void {
        $timer = new FakeTimer(jitterMs: 200);
        $transport = new ScriptedTransport([
            ScriptedTransport::error(503, 'unavailable', ['Retry-After' => $header]),
            ScriptedTransport::json(200, self::VALID),
        ]);

        self::assertSame(self::VALID['data'], $this->send($transport, timer: $timer));
        self::assertSame([$waitMs], $timer->waits);
    }

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function otherRetryAfters(): array
    {
        return [
            'eleven seconds' => ['11', 11_000],
            'a number that is not whole' => ['1.5', null],
            'a negative number' => ['-1', null],
            'text' => ['soon', null],
            'an HTTP date in the past' => ['Mon, 12 Oct 2026 08:59:00 GMT', null],
        ];
    }

    #[Test]
    #[DataProvider('otherRetryAfters')]
    public function usesItsOwnWaitForALongOrInvalidRetryAfterOnA504(
        string $header,
        ?int $retryAfterMs,
    ): void {
        $timer = new FakeTimer(jitterMs: 100);
        $busy = ScriptedTransport::error(504, 'busy', ['Retry-After' => $header]);
        $transport = new ScriptedTransport([$busy, $busy, $busy]);

        $error = $this->failure($transport, timer: $timer);

        self::assertSame($retryAfterMs, $error->retryAfterMs());
        self::assertSame([600, 1100], $timer->waits);
    }
}
