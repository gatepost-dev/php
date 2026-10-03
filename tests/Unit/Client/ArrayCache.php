<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use DateInterval;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * A PSR-16 cache in memory that never expires an entry by itself. It records the TTL of each
 * entry, so a test can check what the client asks of a real cache.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $entries = [];

    /** @var array<string, DateInterval|int|null> */
    public array $ttls = [];

    /** A failure that get() throws, as a broken store does. */
    public ?Throwable $getFails = null;

    /** A failure that get() throws for each key except the generation key of the client. */
    public ?Throwable $entryGetFails = null;

    /** A failure that set() throws. */
    public ?Throwable $setFails = null;

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->getFails !== null) {
            throw $this->getFails;
        }
        if ($this->entryGetFails !== null && $key !== 'gatepost.generation') {
            throw $this->entryGetFails;
        }

        return \array_key_exists($key, $this->entries) ? $this->entries[$key] : $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        if ($this->setFails !== null) {
            throw $this->setFails;
        }

        $this->entries[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];
        $this->ttls = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException('PSR-16 keys are text.');
            }
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->entries);
    }
}
