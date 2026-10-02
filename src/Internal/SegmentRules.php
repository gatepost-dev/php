<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Internal;

use Gatepost\Postcode\ParseError;
use Gatepost\Postcode\ParseErrorCode;
use Gatepost\Postcode\Postcode;
use Gatepost\Postcode\Precision;
use Gatepost\Postcode\Segments;

/**
 * The state rule, the rule for each segment, and the typo fixes that parse suggests.
 *
 * @internal
 */
final class SegmentRules
{
    /**
     * The characters that each kind of segment allows.
     */
    public const PATTERNS = [
        'letters' => '/\A[A-Z]+\z/',
        'digits' => '/\A[0-9]+\z/',
        'letters-or-digits' => '/\A[A-Z0-9]+\z/',
    ];

    // NIPOST has not confirmed which characters a district uses, so no fix changes one.
    private const FIXES = [
        'letters' => SpecData::LETTER_FIXES,
        'digits' => SpecData::DIGIT_FIXES,
        'letters-or-digits' => [],
    ];

    /**
     * Returns the first rule that a code of a valid length breaks, or null when it breaks none.
     * The state comes first, then each segment in order.
     */
    public static function check(string $compact, bool $allowPartial): ?ParseError
    {
        $segments = Segments::fromCompact($compact);
        if (!isset(SpecData::STATES[$segments->state])) {
            $suggestion = self::suggest($segments, $compact, $allowPartial);

            return ParseError::of(ParseErrorCode::UnknownState, Precision::State, $suggestion);
        }
        foreach ($segments->toArray() as $name => $text) {
            if ($text !== null && !self::follows($text, SpecData::SEGMENTS[$name])) {
                $segment = Precision::from($name);
                $suggestion = self::suggest($segments, $compact, $allowPartial);

                return ParseError::of(ParseErrorCode::BadSegment, $segment, $suggestion);
            }
        }

        return null;
    }

    /**
     * @param array{characters: key-of<self::PATTERNS>, minimum: ?int} $rule
     */
    private static function follows(string $text, array $rule): bool
    {
        if (\preg_match(self::PATTERNS[$rule['characters']], $text) !== 1) {
            return false;
        }

        return $rule['minimum'] === null || (int) $text >= $rule['minimum'];
    }

    // A suggestion is a hint for the user. parse never returns the fixed code as a success.
    // The nested parse stops after one level. A fix table never writes a character that it
    // changes, so a fixed code has nothing left to fix, and the second suggest() returns null.
    private static function suggest(
        Segments $segments,
        string $compact,
        bool $allowPartial,
    ): ?string {
        $fixed = '';
        foreach ($segments->toArray() as $name => $text) {
            if ($text !== null) {
                $fixed .= \strtr($text, self::FIXES[SpecData::SEGMENTS[$name]['characters']]);
            }
        }
        if ($fixed === $compact) {
            return null;
        }

        return Postcode::parse($fixed, allowPartial: $allowPartial)->value?->canonical;
    }
}
