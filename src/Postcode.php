<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

use Gatepost\Postcode\Internal\NfkcTable;
use Gatepost\Postcode\Internal\SegmentRules;
use Gatepost\Postcode\Internal\SpecData;
use InvalidArgumentException;

/**
 * A Nigerian digital postcode that parse() accepted, such as EK-01-A03-FK-01. Each form holds
 * the same code. The static methods read text that users type or paste, with no network
 * access.
 *
 * Unofficial. Not made or endorsed by NIPOST.
 */
final class Postcode
{
    /**
     * The version of the Gatepost spec that this package implements.
     */
    public const SPEC_VERSION = '0.2.0';

    // In UTF-8, a code point takes at most 4 bytes. Longer input is over the limit without a
    // count, so a 10 MB string costs no more than a short one.
    private const MAX_BYTES_PER_CODE_POINT = 4;
    private const WITHIN_INPUT_LIMIT = '/\A.{0,' . SpecData::MAX_INPUT_CODE_POINTS . '}\z/su';
    private const LOWER_ASCII = 'abcdefghijklmnopqrstuvwxyz';
    private const UPPER_ASCII = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * The code with no separators, for example EK01A03FK01.
     */
    public readonly string $compact;

    /**
     * The code with hyphens between segments, for example EK-01-A03-FK-01.
     */
    public readonly string $canonical;

    /**
     * The code with spaces between segments, for example EK 01 A03 FK 01.
     */
    public readonly string $display;

    /**
     * Each segment on its own.
     */
    public readonly Segments $segments;

    /**
     * The most precise segment in the code.
     */
    public readonly Precision $precision;

    private function __construct(string $compact, Precision $precision)
    {
        $this->compact = $compact;
        $this->segments = Segments::fromCompact($compact);
        $present = \array_filter(
            $this->segments->toArray(),
            static fn(?string $text): bool => $text !== null,
        );
        $this->canonical = \implode('-', $present);
        $this->display = \implode(' ', $present);
        $this->precision = $precision;
    }

    /**
     * Reads a postcode that a user typed or pasted. It accepts spaces, hyphens, dashes and any
     * letter case. It never throws. Input over the spec's input limit fails with BadLength
     * before parse normalises it, so long text cannot stall a server. Input that is not valid
     * UTF-8 fails with BadCharacter, or with BadLength when it has more than 4 bytes for each
     * code point of the input limit.
     *
     * After normalisation, parse applies these checks in order. The first check that fails
     * gives the error code: Empty, LegacyCode, BadCharacter, BadLength, UnknownState and
     * BadSegment. For UnknownState and BadSegment, the error names the segment, and it can
     * suggest the canonical form of one fixed code. The fix replaces a look-alike character
     * in the wrong place, such as the letter O in an LGA. It never changes the district.
     *
     * ```php
     * $result = Postcode::parse('ek 01 a03 fk 01');
     * if ($result->isOk()) {
     *     echo $result->value->canonical; // EK-01-A03-FK-01
     * }
     * ```
     *
     * @param string $input        Text from a user.
     * @param bool   $allowPartial Also accept a code that stops after the state, LGA, district
     *                             or area.
     */
    public static function parse(string $input, bool $allowPartial = false): ParseResult
    {
        $inputProblem = self::checkInput($input);
        if ($inputProblem !== null) {
            return self::failure($inputProblem);
        }
        $text = self::normalize($input);
        if ($text === '') {
            return self::failure(ParseErrorCode::Empty);
        }
        if (\preg_match(SpecData::LEGACY_PATTERN, $text) === 1) {
            return self::failure(ParseErrorCode::LegacyCode);
        }
        if (\preg_match(SegmentRules::PATTERNS['letters-or-digits'], $text) !== 1) {
            return self::failure(ParseErrorCode::BadCharacter);
        }
        $precision = self::precisionOfLength(\strlen($text));
        if ($precision === null || (!$allowPartial && $precision !== Precision::Unit)) {
            return self::failure(ParseErrorCode::BadLength);
        }
        $segmentError = SegmentRules::check($text, $allowPartial);
        if ($segmentError !== null) {
            return ParseResult::failure($segmentError);
        }

        return ParseResult::success(new self($text, $precision));
    }

    /**
     * Normalises text that a user typed or pasted. It applies Unicode NFKC to each character whose
     * NFKC form holds only ASCII characters and separators, such as full-width letters. It
     * removes the separators that the spec lists: white space, hyphens and dashes, the full
     * stop and some zero-width characters. Then it makes the ASCII letters a to z upper case.
     * It keeps every other character and every byte that is not valid UTF-8. It does not
     * check the result, and it does not limit the length of its input.
     *
     * ```php
     * echo Postcode::normalize(' ek-01 a03.fk-01 '); // EK01A03FK01
     * ```
     *
     * @param string $input Text from a user.
     */
    public static function normalize(string $input): string
    {
        $compatible = \strtr($input, NfkcTable::FORMS);

        return self::upperAscii(\strtr($compatible, SpecData::SEPARATORS));
    }

    /**
     * Tells whether text is an old 6-digit NIPOST postcode, which names an area, not a
     * building. It is a thin wrapper over parse(), so it is false for input over the input
     * limit and for input that is not valid UTF-8.
     *
     * ```php
     * Postcode::isLegacy('900 108'); // true
     * ```
     *
     * @param string $input Text from a user.
     */
    public static function isLegacy(string $input): bool
    {
        return self::parse($input)->error?->code === ParseErrorCode::LegacyCode;
    }

    /**
     * Returns the English name of a state from its two-letter code. The lookup ignores the
     * case of the ASCII letters only. It does not trim spaces or change other characters.
     *
     * ```php
     * echo Postcode::stateName('fc'); // Federal Capital Territory
     * ```
     *
     * @param string $stateCode A state code, such as EK.
     *
     * @return ?string The name, or null for an unknown code.
     */
    public static function stateName(string $stateCode): ?string
    {
        return SpecData::STATES[self::upperAscii($stateCode)] ?? null;
    }

    /**
     * Returns the most precise segment that a GPS fix of this accuracy supports. Each limit
     * in the spec is inclusive, so a fix equal to a limit gets the precision of that limit.
     *
     * ```php
     * Postcode::precisionForAccuracy(35.0); // Precision::District
     * ```
     *
     * @param ?float $accuracyM The accuracy radius in metres. Null, NaN, infinite and negative
     *                          values mean that the accuracy is unknown, which gives the LGA.
     */
    public static function precisionForAccuracy(?float $accuracyM): Precision
    {
        if ($accuracyM === null || $accuracyM < 0) {
            return Precision::from(SpecData::PRECISION_FALLBACK);
        }
        // NaN and positive infinity fail every comparison below, so they reach the fallback.
        foreach (SpecData::PRECISION_THRESHOLDS as $threshold) {
            if ($accuracyM <= $threshold['maxAccuracyM']) {
                return Precision::from($threshold['precision']);
            }
        }

        return Precision::from(SpecData::PRECISION_FALLBACK);
    }

    /**
     * Shortens the postcode to a less precise segment, for example from a building to its area.
     *
     * ```php
     * echo $postcode->truncate(Precision::District)->canonical; // EK-01-A03
     * ```
     *
     * @param Precision $to The precision to keep. It must not be more precise than the code.
     *
     * @throws InvalidArgumentException When $to is more precise than the code.
     */
    public function truncate(Precision $to): self
    {
        $end = SpecData::SEGMENTS[$to->value]['end'];
        if ($end > \strlen($this->compact)) {
            throw new InvalidArgumentException(\sprintf(
                'Cannot truncate a %1$s postcode to %2$s. Choose %1$s or a less precise segment.',
                $this->precision->value,
                $to->value,
            ));
        }

        return new self(\substr($this->compact, 0, $end), $to);
    }

    /**
     * Returns the postcode one segment less precise, such as the area of a building.
     *
     * ```php
     * echo $postcode->parent()?->canonical; // EK-01-A03-FK
     * ```
     *
     * @return ?self The parent, or null for a state code.
     */
    public function parent(): ?self
    {
        $larger = null;
        // SEGMENTS run from the least to the most precise, so the last match is the parent.
        foreach (SpecData::SEGMENTS as $name => $segment) {
            if ($segment['end'] < \strlen($this->compact)) {
                $larger = Precision::from($name);
            }
        }

        return $larger === null ? null : $this->truncate($larger);
    }

    /**
     * Tells whether another postcode lies inside this one, such as a building inside its
     * district. A postcode contains itself.
     *
     * ```php
     * $district->contains($building); // true
     * ```
     *
     * @param self $code The postcode to test.
     */
    public function contains(self $code): bool
    {
        // A precision has one fixed compact length, so a prefix always ends at a segment boundary.
        return \str_starts_with($code->compact, $this->compact);
    }

    /**
     * Hides the unit, so that a log shows the area but not the building. A code without a
     * unit stays as it is.
     *
     * ```php
     * echo $postcode->redact(); // EK-01-A03-FK-**
     * ```
     */
    public function redact(): string
    {
        if ($this->segments->unit === null) {
            return $this->canonical;
        }

        return \substr($this->canonical, 0, -\strlen($this->segments->unit)) . '**';
    }

    private static function checkInput(string $input): ?ParseErrorCode
    {
        if (\strlen($input) > self::MAX_BYTES_PER_CODE_POINT * SpecData::MAX_INPUT_CODE_POINTS) {
            return ParseErrorCode::BadLength;
        }

        // With the u flag, preg_match() returns false for input that is not valid UTF-8.
        return match (\preg_match(self::WITHIN_INPUT_LIMIT, $input)) {
            1 => null,
            0 => ParseErrorCode::BadLength,
            false => ParseErrorCode::BadCharacter,
        };
    }

    private static function precisionOfLength(int $length): ?Precision
    {
        foreach (SpecData::SEGMENTS as $name => $segment) {
            if ($segment['end'] === $length) {
                return Precision::from($name);
            }
        }

        return null;
    }

    private static function failure(ParseErrorCode $code): ParseResult
    {
        return ParseResult::failure(ParseError::of($code));
    }

    private static function upperAscii(string $text): string
    {
        return \strtr($text, self::LOWER_ASCII, self::UPPER_ASCII);
    }
}
