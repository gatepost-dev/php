<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Vectors;

use UnexpectedValueException;

/**
 * One shared test case from spec/vectors. Each reader fails when the case lacks the shape that
 * spec/vectors/README.md gives, so a broken file fails the run instead of passing quietly.
 */
final class VectorCase
{
    /**
     * @param array{input: mixed, options: array<mixed>, expect: array<mixed>} $case
     */
    private function __construct(
        public readonly string $id,
        public readonly string $description,
        private readonly array $case,
    ) {}

    public static function fromJson(mixed $case, string $file): self
    {
        if (
            !\is_array($case)
            || !\is_string($case['id'] ?? null)
            || !\is_string($case['description'] ?? null)
            || !\array_key_exists('input', $case)
            || !\is_array($case['options'] ?? null)
            || !\is_array($case['expect'] ?? null)
        ) {
            throw new UnexpectedValueException("{$file}.json holds a case that lacks a field.");
        }

        return new self($case['id'], $case['description'], [
            'input' => $case['input'],
            'options' => $case['options'],
            'expect' => $case['expect'],
        ]);
    }

    /**
     * The input of a function that takes one string.
     */
    public function text(): string
    {
        if (!\is_string($this->case['input'])) {
            throw new UnexpectedValueException("{$this->id} has no text input.");
        }

        return $this->case['input'];
    }

    /**
     * The input of precisionForAccuracy. JSON has no NaN or infinity, so the vectors name them.
     */
    public function accuracy(): ?float
    {
        $input = $this->case['input'];

        return match (true) {
            $input === null, \is_int($input), \is_float($input) => $input,
            $input === 'NaN' => NAN,
            $input === 'Infinity' => INF,
            $input === '-Infinity' => -INF,
            default => throw new UnexpectedValueException("{$this->id} has no accuracy input."),
        };
    }

    public function allowPartial(): bool
    {
        $allowPartial = $this->case['options']['allowPartial'] ?? false;
        if (!\is_bool($allowPartial)) {
            throw new UnexpectedValueException("{$this->id} has a non-boolean allowPartial.");
        }

        return $allowPartial;
    }

    /**
     * @return array<mixed>
     */
    public function expected(): array
    {
        return $this->case['expect'];
    }

    public function expectedField(string $name): mixed
    {
        if (!\array_key_exists($name, $this->case['expect'])) {
            throw new UnexpectedValueException("{$this->id} expects no field {$name}.");
        }

        return $this->case['expect'][$name];
    }
}
