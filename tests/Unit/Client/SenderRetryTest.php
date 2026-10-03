<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The retries of 502, 503 and 504, and the waits before them.
 */
final class SenderRetryTest extends SenderTestCase
{
    #[Test]
    public function retriesA503AfterTheFirstWaitAndReturnsTheNextAnswer(): void
    {
        $timer = new FakeTimer(jitterMs: 180);
        $transport = new ScriptedTransport([
            ScriptedTransport::error(503, 'unavailable'),
            ScriptedTransport::json(200, self::VALID),
        ]);

        self::assertSame(self::VALID['data'], $this->send($transport, timer: $timer));
        self::assertSame([680], $timer->waits);
    }

    #[Test]
    public function doublesTheWaitBeforeEachLaterRetry(): void
    {
        $timer = new FakeTimer(jitterMs: 250);
        $transport = new ScriptedTransport([
            ScriptedTransport::error(502, 'unavailable'),
            ScriptedTransport::error(504, 'unavailable'),
            ScriptedTransport::json(200, self::VALID),
        ]);

        self::assertSame(self::VALID['data'], $this->send($transport, timer: $timer));
        self::assertSame([750, 1250], $timer->waits);
    }

    #[Test]
    public function stopsAfterTheFirstAttemptAndTwoRetries(): void
    {
        $busy = ScriptedTransport::error(503, 'busy');
        $transport = new ScriptedTransport([$busy, $busy, $busy]);

        $error = $this->failure($transport);

        self::assertSame(ErrorCode::ServerError, $error->errorCode());
        self::assertSame(503, $error->status());
        self::assertSame('busy', $error->apiCode());
        self::assertCount(3, $transport->requests);
        self::assertSame([500, 1000], $this->timer->waits);
    }

    #[Test]
    public function sendsOneAttemptWhenMaxRetriesIsZero(): void
    {
        $transport = new ScriptedTransport([ScriptedTransport::error(503, 'busy')]);

        $error = $this->failure($transport, maxRetries: 0);

        self::assertSame(ErrorCode::ServerError, $error->errorCode());
        self::assertCount(1, $transport->requests);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function finalStatuses(): array
    {
        return [
            'status 400' => [400, 'invalid_request'],
            'status 401' => [401, 'auth_required'],
            'status 402' => [402, 'insufficient_credits'],
            'status 403' => [403, 'level_not_granted'],
            'status 500' => [500, 'internal'],
        ];
    }

    #[Test]
    #[DataProvider('finalStatuses')]
    public function neverRetriesAStatusThatTheSpecCallsFinal(int $status, string $apiCode): void
    {
        $transport = new ScriptedTransport([ScriptedTransport::error($status, $apiCode)]);

        $error = $this->failure($transport);

        self::assertSame($status, $error->status());
        self::assertSame($apiCode, $error->apiCode());
        self::assertCount(1, $transport->requests);
    }

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
