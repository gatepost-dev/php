<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Fields;
use Gatepost\Postcode\Internal\SpecData;
use Gatepost\Postcode\Postcode;
use Gatepost\Postcode\Precision;

/**
 * One way to complete the segment that the user types. The gateway sends the value of that
 * segment only. The client adds the segments that the user typed before it.
 */
final class Suggestion
{
    private function __construct(
        /** The value of the segment, as the gateway sent it, such as Z99. */
        public readonly string $code,
        /** The gateway's label, or null. The gateway has sent none so far. */
        public readonly ?string $label,
        /** The typed segments before this one and then code, or null when that is no postcode. */
        public readonly ?Postcode $postcode,
    ) {}

    /**
     * @internal AutocompleteResult reads each suggestion of the suggestions field.
     *
     * @param string $typed The normalised text that the client sent as q.
     *
     * @throws PostcodeException When the suggestion is not an object, or it has no text in code.
     */
    public static function fromFields(mixed $suggestion, Precision $segment, string $typed): self
    {
        $fields = Fields::object($suggestion);
        $code = Fields::string($fields, 'code');

        return new self(
            $code,
            Fields::textOrNull($fields, 'label'),
            self::postcodeOf($typed, $code, $segment),
        );
    }

    /**
     * The typed segments before the active one, followed by the code. The result counts only when
     * it parses and ends in the active segment. A code of the wrong length can parse as another
     * precision: FC01 after F is the LGA, not the state, and that gives null.
     */
    private static function postcodeOf(string $typed, string $code, Precision $segment): ?Postcode
    {
        $before = \substr($typed, 0, SpecData::SEGMENTS[$segment->value]['start']);
        $postcode = Postcode::parse($before . $code, allowPartial: true)->value;

        return $postcode?->precision === $segment ? $postcode : null;
    }
}
