<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\Internal\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every client writes the same query for the same number, so PHP must print the shortest decimal
 * that reads back as the same number, with no exponent and no trailing zero.
 */
final class DecimalTest extends TestCase
{
    /**
     * @return array<string, array{float, string}>
     */
    public static function numbers(): array
    {
        return [
            'a whole number' => [9.0, '9'],
            'a whole number with zeros' => [250.0, '250'],
            'a negative whole number' => [-90.0, '-90'],
            'zero' => [0.0, '0'],
            'negative zero' => [-0.0, '0'],
            'one decimal' => [0.5, '0.5'],
            'three decimals' => [9.001, '9.001'],
            'a negative decimal' => [-7.25, '-7.25'],
            'the sum 0.1 + 0.2' => [0.1 + 0.2, '0.30000000000000004'],
            'five zeros after the point' => [0.00001, '0.00001'],
            'a number that PHP prints as 1.0E-7' => [0.0000001, '0.0000001'],
            'a number with several digits after an exponent' => [1.5e-9, '0.0000000015'],
            'a negative number with an exponent' => [-2.5e-8, '-0.000000025'],
            'a number that PHP prints as 1.0E+25' => [1e25, '10000000000000000000000000'],
            'a large number with digits' => [1.5e20, '150000000000000000000'],
        ];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function writesTheShortestDecimalWithNoExponent(float $number, string $expected): void
    {
        self::assertSame($expected, Decimal::shortest($number));
        self::assertSame($number + 0.0, (float) Decimal::shortest($number));
    }
}
