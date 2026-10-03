<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client\Internal;

/**
 * Writes a number for a query. Every client writes the same query for the same number, so the
 * form is fixed: the shortest decimal that reads back as the same number, with no exponent and
 * no trailing zero (spec/client.md).
 *
 * @internal
 */
final class Decimal
{
    private const MOST_DIGITS = 17;

    /**
     * @param float $number A finite number.
     */
    public static function shortest(float $number): string
    {
        if ($number === 0.0) {
            return '0';
        }
        // The ini setting serialize_precision changes how PHP prints a float, so search for the
        // fewest digits that read back as the same number instead of trusting it.
        $scientific = '';
        for ($digits = 1; $digits <= self::MOST_DIGITS; ++$digits) {
            $scientific = \sprintf('%.' . ($digits - 1) . 'e', $number);
            if ((float) $scientific === $number) {
                break;
            }
        }

        return self::plain($scientific);
    }

    /**
     * @param string $scientific A number such as -1.25e-7, with at least one digit before the
     *                           point and a sign on the exponent.
     */
    private static function plain(string $scientific): string
    {
        [$mantissa, $exponent] = \explode('e', $scientific);
        $sign = \str_starts_with($mantissa, '-') ? '-' : '';
        // The search stops at the fewest digits that read back, so the last digit is not 0.
        $digits = \str_replace(['-', '.'], '', $mantissa);
        // The count of digits before the decimal point.
        $whole = (int) $exponent + 1;
        if ($whole <= 0) {
            return $sign . '0.' . \str_repeat('0', -$whole) . $digits;
        }
        if (\strlen($digits) <= $whole) {
            return $sign . \str_pad($digits, $whole, '0');
        }

        return $sign . \substr($digits, 0, $whole) . '.' . \substr($digits, $whole);
    }
}
