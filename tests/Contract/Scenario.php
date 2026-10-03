<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Contract;

use UnexpectedValueException;

/**
 * One contract scenario of spec/contract, in the format of spec/contract/README.md. The reader
 * fails loudly on a file that breaks the format, so a broken scenario cannot pass by accident.
 */
final class Scenario
{
    /** The file name without .json. */
    public readonly string $id;

    /** parallel, sequential, or null for a scenario of one call. */
    public readonly ?string $order;

    /** @var array<string, int|string> The client options of the scenario. */
    public readonly array $client;

    /** @var list<array<string, mixed>> */
    public readonly array $calls;

    /** @var array<string, mixed> */
    public readonly array $expect;

    private function __construct(string $file)
    {
        $text = (string) \file_get_contents($file);
        $scenario = \json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        $id = \is_array($scenario) ? ($scenario['id'] ?? null) : null;
        if (!\is_array($scenario) || ($scenario['version'] ?? null) !== 1 || !\is_string($id)) {
            throw new UnexpectedValueException(\basename($file) . ' has no version 1 and no id.');
        }
        $order = $scenario['order'] ?? null;
        $this->id = $id;
        $this->order = \is_string($order) ? $order : null;
        $this->client = self::options($scenario['client'] ?? null, $id);
        $this->calls = self::calls($scenario['calls'] ?? null, $id);
        $this->expect = self::fields($scenario['expect'] ?? null, $id);
    }

    /**
     * Each scenario file, keyed by its id.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $files = \glob(\dirname(__DIR__, 2) . '/spec/contract/*.json');
        if ($files === false || $files === []) {
            throw new UnexpectedValueException('spec/contract holds no scenario. Check out spec.');
        }
        $scenarios = [];
        foreach ($files as $file) {
            $scenario = new self($file);
            $scenarios[$scenario->id] = $scenario;
        }

        return $scenarios;
    }

    /**
     * The scenarios that start their calls at the same time. The client is synchronous, so it
     * never has two requests in flight, and these scenarios cannot apply to it.
     *
     * @return array<string, self>
     */
    public static function parallel(): array
    {
        return \array_filter(
            self::all(),
            static fn(self $scenario): bool => $scenario->isParallel(),
        );
    }

    /**
     * Every scenario that the client can run.
     *
     * @return array<string, self>
     */
    public static function runnable(): array
    {
        return \array_filter(
            self::all(),
            static fn(self $scenario): bool => !$scenario->isParallel(),
        );
    }

    public function isParallel(): bool
    {
        return $this->order === 'parallel';
    }

    /**
     * The outcomes of the scenario, one for each call.
     *
     * @return list<mixed>
     */
    public function outcomes(): array
    {
        $outcomes = $this->expect['outcomes'] ?? null;
        if (!\is_array($outcomes) || !\array_is_list($outcomes)) {
            throw new UnexpectedValueException("{$this->id} has no list of outcomes.");
        }

        return $outcomes;
    }

    /**
     * The number of requests that the scenario expects.
     */
    public function attempts(): int
    {
        $attempts = $this->expect['attempts'] ?? null;
        if (!\is_int($attempts)) {
            throw new UnexpectedValueException("{$this->id} has no number of attempts.");
        }

        return $attempts;
    }

    /**
     * The bounds of each wait between two attempts, or an empty list.
     *
     * @return list<array{min: int, max: int}>
     */
    public function waits(): array
    {
        $waits = [];
        foreach ((array) ($this->expect['waitsMs'] ?? []) as $wait) {
            $min = \is_array($wait) ? ($wait['min'] ?? null) : null;
            $max = \is_array($wait) ? ($wait['max'] ?? null) : null;
            if (!\is_int($min) || !\is_int($max)) {
                throw new UnexpectedValueException("{$this->id} has a wait with no bounds.");
            }
            $waits[] = ['min' => $min, 'max' => $max];
        }

        return $waits;
    }

    /**
     * @return array<string, int|string>
     */
    private static function options(mixed $client, string $id): array
    {
        $options = [];
        foreach (self::fields($client, $id) as $name => $value) {
            if (!\is_int($value) && !\is_string($value)) {
                throw new UnexpectedValueException("{$id} has a client option that is not valid.");
            }
            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function calls(mixed $calls, string $id): array
    {
        if (!\is_array($calls) || !\array_is_list($calls)) {
            throw new UnexpectedValueException("{$id} has no list of calls.");
        }

        return \array_map(static fn(mixed $call): array => self::fields($call, $id), $calls);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(mixed $object, string $id): array
    {
        if (!\is_array($object)) {
            throw new UnexpectedValueException("{$id} has a value that is not an object.");
        }
        $fields = [];
        foreach ($object as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }
}
