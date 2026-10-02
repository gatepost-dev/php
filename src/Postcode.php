<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

use Gatepost\Postcode\Internal\NfkcTable;
use Gatepost\Postcode\Internal\SpecData;

/**
 * A Nigerian digital postcode, such as EK-01-A03-FK-01.
 *
 * Unofficial. Not made or endorsed by NIPOST.
 */
final class Postcode
{
    /**
     * The version of the Gatepost spec that this package implements.
     */
    public const SPEC_VERSION = '0.1.0';

    private const LOWER_ASCII = 'abcdefghijklmnopqrstuvwxyz';
    private const UPPER_ASCII = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Cleans text that a user typed or pasted. It applies Unicode NFKC to each character whose
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

    private static function upperAscii(string $text): string
    {
        return \strtr($text, self::LOWER_ASCII, self::UPPER_ASCII);
    }
}
