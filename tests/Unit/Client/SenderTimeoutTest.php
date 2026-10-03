<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * A failed connection and a timeout. A PSR-18 transport reports both as an exception, so the
 * sender tells them apart by the time that the attempt took.
 */
final class SenderTimeoutTest extends SenderTestCase
{
    #[Test]
    public function retriesAFailedConnectionAndEndsWithNetworkError(): void
    {
        $transport = new ScriptedTransport([5.0, 5.0, 5.0], $this->timer);

        $error = $this->failure($transport);

        self::assertSame(ErrorCode::NetworkError, $error->errorCode());
        self::assertNull($error->status());
        self::assertInstanceOf(NetworkExceptionInterface::class, $error->getPrevious());
        self::assertSame([500, 1000], $this->timer->waits);
    }

    #[Test]
    public function callsAFailureAtTheTimeoutATimeoutAndRetriesIt(): void
    {
        $transport = new ScriptedTransport([8000.0, 8000.0, 8000.0], $this->timer);

        $error = $this->failure($transport);

        self::assertSame(ErrorCode::Timeout, $error->errorCode());
        self::assertNull($error->status());
        self::assertCount(3, $transport->requests);
    }

    #[Test]
    public function callsAFailureJustBeforeTheTimeoutANetworkError(): void
    {
        $transport = new ScriptedTransport([7999.0], $this->timer);

        $error = $this->failure($transport, maxRetries: 0);

        self::assertSame(ErrorCode::NetworkError, $error->errorCode());
    }

    #[Test]
    public function doesNotRetryATimeoutWhenTheCallAsksForNone(): void
    {
        $transport = new ScriptedTransport([8000.0], $this->timer);

        $error = $this->failure($transport, retryTimeouts: false);

        self::assertSame(ErrorCode::Timeout, $error->errorCode());
        self::assertCount(1, $transport->requests);
        self::assertSame([], $this->timer->waits);
    }

    #[Test]
    public function stopsAtOnceWhenTheNextWaitWouldEndAfterTheDeadline(): void
    {
        // The deadline is 3 x 8000 ms plus 750 ms and 1250 ms: 26 000 ms after the first attempt.
        // The first attempt ends at 25 400 ms, and the wait of 750 ms would end at 26 150 ms.
        $timer = new FakeTimer(jitterMs: 250);
        $transport = new ScriptedTransport([25_400.0], $timer);

        $error = $this->failure($transport, timer: $timer);

        self::assertSame(ErrorCode::Timeout, $error->errorCode());
        self::assertCount(1, $transport->requests);
        self::assertSame([], $timer->waits);
    }

    #[Test]
    public function retriesWhenTheNextWaitEndsExactlyAtTheDeadline(): void
    {
        $timer = new FakeTimer(jitterMs: 250);
        $transport = new ScriptedTransport([
            25_250.0,
            ScriptedTransport::json(200, self::VALID),
        ], $timer);

        self::assertSame(self::VALID['data'], $this->send($transport, timer: $timer));
        self::assertSame([750], $timer->waits);
    }
}
