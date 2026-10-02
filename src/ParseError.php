<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

/**
 * The reason for a failed parse. It is a value, not an exception: Postcode::parse() returns
 * it inside a ParseResult.
 */
final class ParseError
{
    private function __construct(
        /** The reason, as a stable code. */
        public readonly ParseErrorCode $code,
        /** The failing segment, for UnknownState and BadSegment only. */
        public readonly ?Precision $segment,
        /** The canonical form of one fixed code, or null. It is a hint only. */
        public readonly ?string $suggestion,
    ) {}

    /**
     * @internal Postcode::parse() creates each error.
     */
    public static function of(
        ParseErrorCode $code,
        ?Precision $segment = null,
        ?string $suggestion = null,
    ): self {
        return new self($code, $segment, $suggestion);
    }
}
