<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Fields;
use Gatepost\Postcode\Precision;

/**
 * The ways to complete the segment that holds the last character that the user typed. The list
 * keeps the gateway's order. A full list can still miss values, because the gateway can cap it.
 */
final class AutocompleteResult
{
    /**
     * @param list<Suggestion> $suggestions
     */
    private function __construct(
        /** The segment that the suggestions complete. */
        public readonly Precision $segment,
        /** @var list<Suggestion> The suggestions, in the gateway's order. */
        public readonly array $suggestions,
    ) {}

    /**
     * @internal PostcodeClient reads each autocomplete response with this method.
     *
     * @param string $typed The normalised text that the client sent as q.
     *
     * @throws PostcodeException When the segment is not one of the five, suggestions is not a
     *                           list, or a suggestion has no text in code.
     */
    public static function fromResponse(mixed $response, string $typed): self
    {
        $fields = Fields::object($response);
        $segment = Precision::tryFrom(Fields::string($fields, 'segment'))
            ?? throw PostcodeException::unreadable(200);
        $suggestions = [];
        foreach (Fields::list($fields, 'suggestions') as $suggestion) {
            $suggestions[] = Suggestion::fromFields($suggestion, $segment, $typed);
        }

        return new self($segment, $suggestions);
    }
}
