<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode;

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
     * in the spec is inclusive, so a fix of exactly 8 m supports the unit.
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
        if ($accuracyM === null || !\is_finite($accuracyM) || $accuracyM < 0) {
            return Precision::from(SpecData::PRECISION_FALLBACK);
        }
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
