<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The cache of the client: off by default, on with cacheTtlMs and a PSR-16 cache, never for an
 * error, and emptied by clearCache().
 */
final class CacheTest extends TestCase
{
    private const TTL_MS = 60_000;

    #[Test]
    public function sendsARequestForEachCallWhileTheCacheIsOff(): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();
        $client = new PostcodeClient($gateway, new HttpFactory(), cache: $store);

        $client->lookup(FixtureGateway::UNIT);
        $client->lookup(FixtureGateway::UNIT);

        self::assertCount(2, $gateway->requests);
        self::assertSame([], $store->entries);
    }

    #[Test]
    public function answersASecondIdenticalCallFromTheCache(): void
    {
        $gateway = new FixtureGateway();
        $client = self::cachingClient($gateway, new ArrayCache());

        $first = $client->lookup(FixtureGateway::UNIT);
        $second = $client->lookup('fc01z99zz01');
        $client->reverse(9.0, 7.0);
        $client->reverse(9.0, 7.0);

        self::assertCount(2, $gateway->requests);
        self::assertEquals($first, $second);
    }

    #[Test]
    public function keepsEachLevelAndEachKeyApart(): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();
        $client = self::cachingClient($gateway, $store);
        $other = self::cachingClient($gateway, $store, 'nipost_test_mock_l2');

        $client->lookup(FixtureGateway::UNIT, level: 1);
        $client->lookup(FixtureGateway::UNIT, level: 2);
        $other->lookup(FixtureGateway::UNIT, level: 1);

        self::assertCount(3, $gateway->requests);
    }

    #[Test]
    public function keepsNoError(): void
    {
        $transport = new ScriptedTransport([
            ScriptedTransport::error(400, 'invalid_request'),
            FixtureGateway::fixture('lookup/valid-level-1'),
        ]);
        $client = self::cachingClient($transport, new ArrayCache());

        try {
            $client->lookup(FixtureGateway::UNIT);
            self::fail('The first lookup did not fail.');
        } catch (PostcodeException) {
            self::assertTrue($client->lookup(FixtureGateway::UNIT)->valid);
        }
        self::assertCount(2, $transport->requests);
    }

    #[Test]
    public function sendsARequestAgainAfterClearCache(): void
    {
        $gateway = new FixtureGateway();
        $client = self::cachingClient($gateway, new ArrayCache());

        $client->lookup(FixtureGateway::UNIT);
        $client->clearCache();
        $client->lookup(FixtureGateway::UNIT);

        self::assertCount(2, $gateway->requests);
    }

    #[Test]
    public function keepsNoResultOfARequestThatStartedBeforeClearCache(): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();
        // The transport calls clearCache() while the request is in flight, as a fiber can.
        $transport = new class ($gateway) implements ClientInterface {
            public ?PostcodeClient $client = null;

            public function __construct(private readonly ClientInterface $inner) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->client?->clearCache();

                return $this->inner->sendRequest($request);
            }
        };
        $client = self::cachingClient($transport, $store);
        $transport->client = $client;

        $client->lookup(FixtureGateway::UNIT);
        $client->lookup(FixtureGateway::UNIT);

        self::assertCount(2, $gateway->requests);
    }

    #[Test]
    public function sharesTheResultsOfTwoClientsWithTheSameKeyAndCache(): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();

        self::cachingClient($gateway, $store)->lookup(FixtureGateway::UNIT);
        self::cachingClient($gateway, $store)->lookup(FixtureGateway::UNIT);

        self::assertCount(1, $gateway->requests);
    }

    private static function cachingClient(
        ClientInterface $transport,
        ArrayCache $store,
        string $apiKey = 'nipost_test_mock_l3',
    ): PostcodeClient {
        return new PostcodeClient(
            $transport,
            new HttpFactory(),
            apiKey: $apiKey,
            cacheTtlMs: self::TTL_MS,
            cache: $store,
        );
    }
}
