<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The setup that the README recommends: the transport times out after 15 s, and the client gets
 * the same value as timeoutMs. A gateway that hangs makes each attempt fail after 15 s. The fake
 * clock moves with each scripted failure, so no test waits.
 */
final class TransportTimeoutSetupTest extends TestCase
{
    private const TRANSPORT_TIMEOUT_MS = 15_000;

    private FakeTimer $timer;

    protected function setUp(): void
    {
        $this->timer = new FakeTimer();
    }

    #[Test]
    public function endsAHangingLookupWithATimeoutAfterThreeAttempts(): void
    {
        $transport = $this->hangingTransport(3);

        $error = $this->failure(
            $transport,
            static fn(PostcodeClient $client): mixed => $client->lookup(FixtureGateway::UNIT),
        );

        self::assertSame(ErrorCode::Timeout, $error->errorCode());
        self::assertCount(3, $transport->requests);
        // Three attempts of 15 s, and the waits of 500 and 1000 ms, which jitter does not move.
        self::assertSame([500, 1000], $this->timer->waits);
        self::assertEqualsWithDelta(46_500.0, $this->elapsedMs(), 0.001);
    }

    #[Test]
    public function endsAHangingAutocompleteWithATimeoutAfterOneAttempt(): void
    {
        $transport = $this->hangingTransport(1);

        $error = $this->failure(
            $transport,
            static fn(PostcodeClient $client): mixed => $client->autocomplete('F'),
        );

        self::assertSame(ErrorCode::Timeout, $error->errorCode());
        self::assertCount(1, $transport->requests);
        self::assertSame([], $this->timer->waits);
    }

    private function hangingTransport(int $attempts): ScriptedTransport
    {
        return new ScriptedTransport(
            \array_fill(0, $attempts, (float) self::TRANSPORT_TIMEOUT_MS),
            $this->timer,
        );
    }

    private function elapsedMs(): float
    {
        return $this->timer->nowMs() - FakeTimer::START_MS;
    }

    /**
     * @param callable(PostcodeClient): mixed $call
     */
    private function failure(ScriptedTransport $transport, callable $call): PostcodeException
    {
        $client = new PostcodeClient(
            $transport,
            new HttpFactory(),
            timeoutMs: self::TRANSPORT_TIMEOUT_MS,
            timer: $this->timer,
        );
        try {
            $call($client);
        } catch (PostcodeException $error) {
            return $error;
        }

        self::fail('The call did not fail.');
    }
}
