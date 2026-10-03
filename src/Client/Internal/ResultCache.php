<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client\Internal;

use Psr\SimpleCache\CacheInterface;

/**
 * Keeps the data of each answered call in the caller's PSR-16 cache for cacheTtlMs (SEC-3). The
 * key of each entry holds a generation, and clear() starts a new generation. So clear() makes
 * every kept result unreachable and leaves the caller's other entries alone. The old entries
 * expire with their TTL, so the caller's cache bounds their number.
 *
 * A call takes its slot before it sends the request and puts the data in that same slot. If
 * clear() runs in between, the slot belongs to the old generation, and the data stays unreachable.
 *
 * @internal
 */
final class ResultCache
{
    // PSR-16 lets a key hold A to Z, a to z, 0 to 9, _ and the full stop, up to 64 characters.
    private const PREFIX = 'gatepost.';
    private const GENERATION_KEY = 'gatepost.generation';
    private const GENERATION = '/\A[0-9a-f]{8}\z/';
    private const HASH_LENGTH = 40;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttlMs,
        private readonly Timer $timer,
    ) {}

    /**
     * The key of a call in the current generation.
     *
     * @param string $call The method and the canonical arguments of the call, with the API key.
     *                     The cache sees only a hash of it, so its keys hold no postcode and no
     *                     API key.
     */
    public function slot(string $call): string
    {
        $hash = \substr(\hash('sha256', $call), 0, self::HASH_LENGTH);

        return self::PREFIX . $this->generation() . '.' . $hash;
    }

    /**
     * @return mixed The kept data, or null when nothing is kept. A result never has null data.
     */
    public function get(string $slot): mixed
    {
        $entry = $this->cache->get($slot);
        $expiresAtMs = \is_array($entry) ? ($entry['expiresAtMs'] ?? null) : null;
        if (!\is_float($expiresAtMs) || $expiresAtMs <= $this->timer->nowMs()) {
            return null;
        }

        return $entry['data'] ?? null;
    }

    /**
     * Keeps the data of a call that the client read without an error.
     */
    public function put(string $slot, mixed $data): void
    {
        $entry = ['expiresAtMs' => $this->timer->nowMs() + $this->ttlMs, 'data' => $data];
        // PSR-16 counts whole seconds, so the entry can outlive its TTL by up to one second.
        // get() checks the time in milliseconds.
        $this->cache->set($slot, $entry, (int) \ceil($this->ttlMs / 1000));
    }

    /**
     * Makes every result that this cache kept unreachable.
     */
    public function clear(): void
    {
        $this->cache->set(self::GENERATION_KEY, self::newGeneration());
    }

    // A cache can evict the generation like any other entry. A new one then hides the old
    // entries, so an old result never comes back after clear().
    private function generation(): string
    {
        $generation = $this->cache->get(self::GENERATION_KEY);
        if (\is_string($generation) && \preg_match(self::GENERATION, $generation) === 1) {
            return $generation;
        }
        $generation = self::newGeneration();
        $this->cache->set(self::GENERATION_KEY, $generation);

        return $generation;
    }

    private static function newGeneration(): string
    {
        return \bin2hex(\random_bytes(4));
    }
}
