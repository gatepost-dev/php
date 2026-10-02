<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Fixtures\ApiDump;

final class Square extends Base
{
    public const UNIT = 1.0;
    final public const SIDES_COUNT = 4;
    public const NOTE = "Don't use \\ here";
    private const SECRET = 'secret';

    public static int $count = 0;

    private string $note = 'note';

    public function __construct(public readonly float $side = self::UNIT) {}

    public function area(): float
    {
        return $this->side * $this->side;
    }

    public function name(): string
    {
        return $this->reset() . self::SECRET . $this->note;
    }

    public static function of(float $side = 2.5): self
    {
        return new self($side);
    }

    // PHP 8.5 reports self as the class name, so a type that names the class must read as self.
    public function twin(Square $other): Square
    {
        return $other;
    }

    public function join(string ...$parts): string
    {
        return \implode(',', $parts);
    }

    public function swap(int &$left, int &$right): void
    {
        [$left, $right] = [$right, $left];
    }

    public function scale(int|float $factor): static
    {
        return new static($this->side * $factor);
    }

    /**
     * @param array<string, int> $options
     */
    public function configure(
        array $options = [],
        ?string $label = null,
        Colour $colour = Colour::Red,
        bool $strict = false,
    ): void {}

    /**
     * @internal Only the package calls this method.
     */
    public function wire(): void {}

    protected function guard(): void {}

    private function reset(): string
    {
        return '';
    }
}
