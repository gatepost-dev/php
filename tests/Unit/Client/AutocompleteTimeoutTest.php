<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The timeout of each call. A PSR-18 transport has no timeout of its own, so the client calls an
 * attempt that failed after timeoutMs or more a timeout, and a failure that came sooner a
 * network error. The default is 8 s, and 15 s for autocomplete, because a user waits for each
 * completion. A timeout of autocomplete gets no retry, because the next keystroke replaces it.
 * The fake clock moves with each scripted failure, so no test waits.
 */
final class AutocompleteTimeoutTest extends TestCase
{
    /**
     * @return array<string, array{?int, float, ErrorCode}>
     */
    public static function autocompleteFailures(): array
    {
        return [
            'default, 1 ms short' => [null, 14999.0, ErrorCode::NetworkError],
            'default, on the limit' => [null, 15000.0, ErrorCode::Timeout],
            'given, 1 ms short' => [200, 199.0, ErrorCode::NetworkError],
            'given, on the limit' => [200, 200.0, ErrorCode::Timeout],
        ];
    }

    #[Test]
    #[DataProvider('autocompleteFailures')]
    public function autocompleteReadsAFailureByTheTimeoutOfTheCall(
        ?int $timeoutMs,
        float $failsAfterMs,
        ErrorCode $expected,
    ): void {
        $timer = new FakeTimer();
        $client = new PostcodeClient(
            new ScriptedTransport([$failsAfterMs], $timer),
            new HttpFactory(),
            timeoutMs: $timeoutMs,
            maxRetries: 0,
            timer: $timer,
        );

        try {
            $client->autocomplete('F');
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame($expected, $error->errorCode());
        }
    }

    /**
     * @return array<string, array{float, ErrorCode}>
     */
    public static function lookupFailures(): array
    {
        return [
            '1 ms short' => [7999.0, ErrorCode::NetworkError],
            'on the limit' => [8000.0, ErrorCode::Timeout],
        ];
    }

    #[Test]
    #[DataProvider('lookupFailures')]
    public function lookupReadsAFailureByTheDefaultOfEightSeconds(
        float $failsAfterMs,
        ErrorCode $expected,
    ): void {
        $timer = new FakeTimer();
        $client = new PostcodeClient(
            new ScriptedTransport([$failsAfterMs], $timer),
            new HttpFactory(),
            maxRetries: 0,
            timer: $timer,
        );

        try {
            $client->lookup(FixtureGateway::UNIT);
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame($expected, $error->errorCode());
        }
    }

    #[Test]
    public function doesNotRetryATimeoutOfAutocomplete(): void
    {
        $timer = new FakeTimer();
        $transport = new ScriptedTransport([15000.0], $timer);
        $client = new PostcodeClient(
            $transport,
            new HttpFactory(),
            maxRetries: 2,
            timer: $timer,
        );

        try {
            $client->autocomplete('F');
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::Timeout, $error->errorCode());
        }
        self::assertCount(1, $transport->requests);
        self::assertSame([], $timer->waits);
    }
}
