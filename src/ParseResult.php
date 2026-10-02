<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

/**
 * The result of Postcode::parse(): a postcode, or the reason that the input is not one.
 * Exactly one of value and error is set.
 *
 * ```php
 * $result = Postcode::parse($input);
 * if ($result->isOk()) {
 *     echo $result->value->canonical;
 * } else {
 *     echo $result->error->code->value;
 * }
 * ```
 */
final class ParseResult
{
    private function __construct(
        /** The postcode, or null when the parse failed. */
        public readonly ?Postcode $value,
        /** Why the parse failed, or null when it succeeded. */
        public readonly ?ParseError $error,
    ) {}

    /**
     * @internal Postcode::parse() creates each result.
     */
    public static function success(Postcode $value): self
    {
        return new self($value, null);
    }

    /**
     * @internal Postcode::parse() creates each result.
     */
    public static function failure(ParseError $error): self
    {
        return new self(null, $error);
    }

    /**
     * Tells whether the parse found a postcode. Static analysers such as PHPStan then know
     * which of value and error is set.
     *
     * @phpstan-assert-if-true !null $this->value
     * @phpstan-assert-if-true null $this->error
     * @phpstan-assert-if-false null $this->value
     * @phpstan-assert-if-false !null $this->error
     */
    public function isOk(): bool
    {
        return $this->value !== null;
    }
}
