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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The options of the client: the programmer errors that spec/client.md names, and the key,
 * which no error message may hold (ERR-3).
 */
final class ClientOptionsTest extends TestCase
{
    private const KEY = 'nipost_test_mock_l3';

    /**
     * @return array<string, array{Closure(): PostcodeClient, string}>
     */
    public static function badOptions(): array
    {
        $gateway = new FixtureGateway();
        $factory = new HttpFactory();

        return [
            'a timeout of 0' => [
                static fn(): PostcodeClient => new PostcodeClient(
                    $gateway,
                    $factory,
                    timeoutMs: 0,
                ),
                'timeoutMs is 0. Use 1 or more.',
            ],
            'a negative timeout' => [
                static fn(): PostcodeClient => new PostcodeClient(
                    $gateway,
                    $factory,
                    timeoutMs: -1,
                ),
                'timeoutMs is -1. Use 1 or more.',
            ],
            'negative retries' => [
                static fn(): PostcodeClient => new PostcodeClient(
                    $gateway,
                    $factory,
                    maxRetries: -1,
                ),
                'maxRetries is -1. Use 0 or more.',
            ],
            'a negative cache time' => [
                static fn(): PostcodeClient => new PostcodeClient(
                    $gateway,
                    $factory,
                    cacheTtlMs: -1,
                ),
                'cacheTtlMs is -1. Use 0 or more.',
            ],
            'a cache time with no cache' => [
                static fn(): PostcodeClient => new PostcodeClient(
                    $gateway,
                    $factory,
                    cacheTtlMs: 1000,
                ),
                'cacheTtlMs is above 0. Pass a PSR-16 cache too.',
            ],
            'an empty key' => [
                static fn(): PostcodeClient => new PostcodeClient(
                    $gateway,
                    $factory,
                    apiKey: '',
                ),
                'apiKey is empty. Pass null to send no key.',
            ],
        ];
    }

    /**
     * @param Closure(): PostcodeClient $create
     */
    #[Test]
    #[DataProvider('badOptions')]
    public function rejectsAnOptionOutOfRangeAsAProgrammerError(
        Closure $create,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $create();
    }

    #[Test]
    public function acceptsNoRetriesAndTheShortestTimeout(): void
    {
        $client = new PostcodeClient(
            new FixtureGateway(),
            new HttpFactory(),
            timeoutMs: 1,
            maxRetries: 0,
        );

        self::assertTrue($client->lookup(FixtureGateway::UNIT)->valid);
    }

    /**
     * @return array<string, array{ScriptedTransport}>
     */
    public static function failures(): array
    {
        $answers = [
            'status 401' => ScriptedTransport::error(401, 'invalid_api_key'),
            'status 403' => ScriptedTransport::error(403, 'level_not_granted'),
            'status 429' => ScriptedTransport::error(429, 'rate_limited'),
            'a failed connection' => 1.0,
        ];

        return \array_map(
            static fn(ResponseInterface|float $answer): array => [new ScriptedTransport([$answer])],
            $answers,
        );
    }

    #[Test]
    #[DataProvider('failures')]
    public function keepsTheKeyOutOfEachErrorMessage(ScriptedTransport $transport): void
    {
        $client = new PostcodeClient(
            $transport,
            new HttpFactory(),
            apiKey: self::KEY,
            maxRetries: 0,
        );

        try {
            $client->lookup(FixtureGateway::UNIT);
            self::fail('The lookup did not fail.');
        } catch (PostcodeException $error) {
            self::assertNotSame(ErrorCode::InvalidInput, $error->errorCode());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
            self::assertStringNotContainsString('nipost_', $error->getMessage());
        }
    }
}
