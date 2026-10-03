<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Closure;
use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The retry rules that hold for each call of the client: every call except autocomplete retries a
 * timeout, and a client with no maxRetries retries twice. The fake clock moves with each scripted
 * failure, so no test waits.
 */
final class RetryByCallTest extends TestCase
{
    /**
     * @return array<string, array{Closure(PostcodeClient): mixed, string}>
     */
    public static function callsThatRetryATimeout(): array
    {
        return [
            'lookup' => [
                static fn(PostcodeClient $client): mixed => $client->lookup(FixtureGateway::UNIT),
                'lookup/valid-level-1',
            ],
            'reverse' => [
                static fn(PostcodeClient $client): mixed => $client->reverse(9.0, 7.0),
                'reverse/unit',
            ],
        ];
    }

    /**
     * @param Closure(PostcodeClient): mixed $call
     */
    #[Test]
    #[DataProvider('callsThatRetryATimeout')]
    public function retriesATimeoutOnceAndKeepsTheLaterAnswer(Closure $call, string $fixture): void
    {
        $timer = new FakeTimer();
        $transport = new ScriptedTransport(
            [8000.0, FixtureGateway::fixture($fixture)],
            $timer,
        );
        $client = new PostcodeClient(
            $transport,
            new HttpFactory(),
            timeoutMs: 8000,
            timer: $timer,
        );

        $call($client);

        self::assertCount(2, $transport->requests);
        self::assertSame([500], $timer->waits);
    }

    #[Test]
    public function retriesTwiceWhenTheCallerSetsNoMaxRetries(): void
    {
        $timer = new FakeTimer();
        $transport = new ScriptedTransport([
            ScriptedTransport::error(503, 'unavailable'),
            ScriptedTransport::error(503, 'unavailable'),
            ScriptedTransport::error(503, 'unavailable'),
        ]);
        $client = new PostcodeClient($transport, new HttpFactory(), timer: $timer);

        try {
            $client->lookup(FixtureGateway::UNIT);
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::ServerError, $error->errorCode());
        }
        self::assertCount(3, $transport->requests);
        self::assertSame([500, 1000], $timer->waits);
    }
}
