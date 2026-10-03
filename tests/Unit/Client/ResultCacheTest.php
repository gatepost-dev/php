<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\Internal\ResultCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The time limit and the clear command that SEC-3 asks of every cache, in the caller's PSR-16
 * cache, with a fake clock.
 */
final class ResultCacheTest extends TestCase
{
    private const KEY = 'nipost_test_mock_l3';
    private const URL = 'https://gateway.invalid/v1/lookup?code=FC-01-Z99-ZZ-01&level=1';
    private const DATA = ['postcode' => 'FC-01-Z99-ZZ-01', 'valid' => true];

    private ArrayCache $store;

    private FakeTimer $timer;

    private ResultCache $cache;

    protected function setUp(): void
    {
        $this->store = new ArrayCache();
        $this->timer = new FakeTimer();
        $this->cache = new ResultCache($this->store, 1500, $this->timer);
    }

    #[Test]
    public function givesBackTheDataUntilTheTtlEnds(): void
    {
        $this->cache->put($this->slot(), self::DATA);
        $this->timer->advance(1499);

        self::assertSame(self::DATA, $this->cache->get($this->slot()));

        $this->timer->advance(1);

        self::assertNull($this->cache->get($this->slot()));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function ttls(): array
    {
        return [
            'one millisecond' => [1, 1],
            'just over one second' => [1001, 2],
            'two seconds' => [2000, 2],
        ];
    }

    #[Test]
    #[DataProvider('ttls')]
    public function asksTheCacheForWholeSecondsRoundedUp(int $ttlMs, int $seconds): void
    {
        $cache = new ResultCache($this->store, $ttlMs, $this->timer);

        $cache->put(self::slotOf($cache), self::DATA);

        $resultTtls = \array_filter($this->store->ttls, static fn($ttl): bool => $ttl !== null);
        self::assertSame([$seconds], \array_values($resultTtls));
    }

    #[Test]
    public function keepsEachCallUnderAKeyThatPsr16Allows(): void
    {
        $this->cache->put($this->slot(), self::DATA);
        $this->cache->put($this->slot(self::URL . '&other=1'), self::DATA);

        $keys = \array_keys($this->store->entries);
        self::assertCount(3, $keys);
        self::assertContains('gatepost.generation', $keys);
        foreach (\array_diff($keys, ['gatepost.generation']) as $key) {
            // A hash of the call, so that no key holds the postcode or the API key.
            self::assertMatchesRegularExpression('/\Agatepost\.[0-9a-f]{8}\.[0-9a-f]{40}\z/', $key);
        }
    }

    #[Test]
    public function hashesTheCallWithTheApiKeyAsTheHmacKey(): void
    {
        $slot = $this->slot();

        $hash = \substr(\hash_hmac('sha256', self::URL, self::KEY), 0, 40);
        self::assertStringEndsWith('.' . $hash, $slot);
        self::assertStringNotContainsString(self::KEY, $slot);
        self::assertNotSame($slot, $this->cache->slot(self::URL, 'another_key'));
    }

    #[Test]
    public function hidesEveryKeptResultAfterClearAndKeepsTheOtherEntries(): void
    {
        $this->store->set('app.session', 'kept');
        $this->cache->put($this->slot(), self::DATA);

        $this->cache->clear();

        self::assertNull($this->cache->get($this->slot()));
        self::assertSame('kept', $this->store->get('app.session'));
    }

    #[Test]
    public function keepsNoResultOfACallThatStartedBeforeClear(): void
    {
        $slot = $this->slot();

        $this->cache->clear();
        $this->cache->put($slot, self::DATA);

        self::assertNull($this->cache->get($this->slot()));
    }

    #[Test]
    public function hidesTheOldResultsWhenTheCacheEvictsTheGeneration(): void
    {
        $this->cache->put($this->slot(), self::DATA);

        $this->store->delete('gatepost.generation');

        self::assertNull($this->cache->get($this->slot()));
    }

    #[Test]
    public function readsAnEntryOfAnotherShapeAsNothingKept(): void
    {
        $this->cache->put($this->slot(), self::DATA);
        foreach (\array_keys($this->store->entries) as $key) {
            if ($key !== 'gatepost.generation') {
                $this->store->set($key, ['data' => self::DATA]);
            }
        }

        self::assertNull($this->cache->get($this->slot()));
    }

    private function slot(string $url = self::URL): string
    {
        return self::slotOf($this->cache, $url);
    }

    private static function slotOf(ResultCache $cache, string $url = self::URL): string
    {
        $slot = $cache->slot($url, self::KEY);
        self::assertNotNull($slot);

        return $slot;
    }
}
