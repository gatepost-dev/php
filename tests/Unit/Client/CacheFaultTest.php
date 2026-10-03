<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\PostcodeClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * A cache is an extra. A store that fails, or that returns something that the client cannot
 * read, must cost a request and nothing more: the caller still gets the result that the gateway
 * sent. The client never writes the key or the postcode in a cache key.
 */
final class CacheFaultTest extends TestCase
{
    /**
     * @return array<string, array{Throwable}>
     */
    public static function failures(): array
    {
        return [
            'a runtime error' => [new RuntimeException('The store is down.')],
            'a PSR-16 exception' => [new StoreRefused('The store refused it.')],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function readsAStoreThatFailsOnReadAsAMiss(Throwable $failure): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();
        $store->getFails = $failure;
        $client = self::cachingClient($gateway, $store);

        self::assertTrue($client->lookup(FixtureGateway::UNIT)->valid);
        self::assertTrue($client->lookup(FixtureGateway::UNIT)->valid);

        self::assertCount(2, $gateway->requests);
    }

    #[Test]
    #[DataProvider('failures')]
    public function keepsTheResultWhenTheStoreFailsOnWrite(Throwable $failure): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();
        $store->setFails = $failure;
        $client = self::cachingClient($gateway, $store);

        $result = $client->reverse(9.0, 7.0);

        self::assertTrue($result->found);
        self::assertCount(1, $gateway->requests);
    }

    #[Test]
    public function keepsTheResultWhenOnlyTheEntryWriteFails(): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();
        $client = self::cachingClient($gateway, $store);
        // The first call makes the generation key. The next call fails only on its entry.
        $client->lookup(FixtureGateway::UNIT);
        $store->setFails = new RuntimeException('The store is full.');

        self::assertTrue($client->lookup(FixtureGateway::UNIT, level: 2)->valid);
    }

    #[Test]
    public function readsAKeptEntryThatTheClientCannotReadAsAMissAndReplacesIt(): void
    {
        $gateway = new FixtureGateway();
        $store = new ArrayCache();
        $client = self::cachingClient($gateway, $store);
        $client->lookup(FixtureGateway::UNIT);
        foreach (\array_keys($store->entries) as $key) {
            $entry = $store->entries[$key];
            if (\is_array($entry) && \array_key_exists('data', $entry)) {
                $entry['data'] = ['valid' => 'zzz'];
                $store->entries[$key] = $entry;
            }
        }

        self::assertTrue($client->lookup(FixtureGateway::UNIT)->valid);
        self::assertTrue($client->lookup(FixtureGateway::UNIT)->valid);

        self::assertCount(2, $gateway->requests);
    }

    #[Test]
    public function tellsTheCallerWhenClearCacheFails(): void
    {
        $store = new ArrayCache();
        $client = self::cachingClient(new FixtureGateway(), $store);
        $store->setFails = new RuntimeException('The store is down.');

        $this->expectException(RuntimeException::class);

        $client->clearCache();
    }

    #[Test]
    public function holdsNeitherTheApiKeyNorThePostcodeInAnyKey(): void
    {
        $store = new ArrayCache();
        $client = self::cachingClient(new FixtureGateway(), $store);

        $client->lookup(FixtureGateway::UNIT);

        foreach (\array_keys($store->entries) as $key) {
            self::assertStringNotContainsString('nipost', $key);
            self::assertStringNotContainsString('Z99', $key);
        }
    }

    private static function cachingClient(
        FixtureGateway $gateway,
        ArrayCache $store,
    ): PostcodeClient {
        return new PostcodeClient(
            $gateway,
            new HttpFactory(),
            apiKey: 'nipost_test_mock_l3',
            cacheTtlMs: 60_000,
            cache: $store,
        );
    }
}
