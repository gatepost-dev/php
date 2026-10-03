<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A 429. The gateway counts the requests of each clock minute, so the client waits only when
 * Retry-After asks for 10 seconds or less.
 */
final class SenderRateLimitTest extends SenderTestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function shortRetryAfters(): array
    {
        return [
            'one second' => ['1', 1000],
            'ten seconds, the longest that the client waits' => ['10', 10_000],
            'an HTTP date three seconds ahead' => ['Mon, 12 Oct 2026 09:00:03 GMT', 3000],
            'an RFC 850 date three seconds ahead' => ['Monday, 12-Oct-26 09:00:03 GMT', 3000],
            'an asctime date three seconds ahead' => ['Mon Oct 12 09:00:03 2026', 3000],
            'an HTTP date in the current second' => ['Mon, 12 Oct 2026 09:00:00 GMT', 0],
        ];
    }

    #[Test]
    #[DataProvider('shortRetryAfters')]
    public function waitsExactlyAsLongAsAShortRetryAfterAsks(string $header, int $waitMs): void
    {
        $timer = new FakeTimer(jitterMs: 200);
        $transport = new ScriptedTransport([
            ScriptedTransport::error(429, 'rate_limited', ['Retry-After' => $header]),
            ScriptedTransport::json(200, self::VALID),
        ]);

        self::assertSame(self::VALID['data'], $this->send($transport, timer: $timer));
        self::assertSame([$waitMs], $timer->waits);
    }

    /**
     * @return array<string, array{array<string, string>, ?int}>
     */
    public static function finalRateLimits(): array
    {
        return [
            'no Retry-After' => [[], null],
            'a Retry-After of eleven seconds' => [['Retry-After' => '11'], 11_000],
            'a Retry-After of two minutes' => [['Retry-After' => '120'], 120_000],
            'a Retry-After that is not a number or a date' => [['Retry-After' => 'soon'], null],
            'an HTTP date in the past' => [
                ['Retry-After' => 'Mon, 12 Oct 2026 08:59:00 GMT'],
                null,
            ],
            'a date that does not exist' => [
                ['Retry-After' => 'Sat, 31 Feb 2027 09:00:03 GMT'],
                null,
            ],
            'an asctime date with a padded day' => [
                ['Retry-After' => 'Tue Nov  3 09:00:03 2026'],
                1_900_803_000,
            ],
            'a date with the wrong weekday' => [
                ['Retry-After' => 'Fri, 12 Oct 2026 09:00:03 GMT'],
                null,
            ],
            'an RFC 850 date with the wrong weekday' => [
                ['Retry-After' => 'Friday, 12-Oct-26 09:00:03 GMT'],
                null,
            ],
            'an asctime date with the wrong weekday' => [
                ['Retry-After' => 'Fri Oct 12 09:00:03 2026'],
                null,
            ],
            'a date that rolls over, with the weekday of the new date' => [
                ['Retry-After' => 'Wed, 31 Feb 2027 09:00:03 GMT'],
                null,
            ],
            'a date with a minute of 60' => [
                ['Retry-After' => 'Mon, 12 Oct 2026 09:60:03 GMT'],
                null,
            ],
            'a date with a month that does not exist' => [
                ['Retry-After' => 'Mon, 12 Foo 2026 09:00:03 GMT'],
                null,
            ],
            'a date with a lower case month' => [
                ['Retry-After' => 'Mon, 12 oct 2026 09:00:03 GMT'],
                null,
            ],
            'a date with a numeric zone' => [
                ['Retry-After' => 'Mon, 12 Oct 2026 09:00:03 +0000'],
                null,
            ],
            'an asctime date with a zone' => [
                ['Retry-After' => 'Mon Oct 12 09:00:03 2026 GMT'],
                null,
            ],
            'a date with text after it' => [
                ['Retry-After' => 'Mon, 12 Oct 2026 09:00:03 GMT and more'],
                null,
            ],
            'a number of ten digits' => [['Retry-After' => '1000000000'], 1_000_000_000_000],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('finalRateLimits')]
    public function failsAtOnceWithRateLimitedWhenTheWaitIsLongOrUnknown(
        array $headers,
        ?int $retryAfterMs,
    ): void {
        $transport = new ScriptedTransport([
            ScriptedTransport::error(429, 'rate_limited', $headers),
        ]);

        $error = $this->failure($transport);

        self::assertSame(ErrorCode::RateLimited, $error->errorCode());
        self::assertSame($retryAfterMs, $error->retryAfterMs());
        self::assertCount(1, $transport->requests);
    }
}
