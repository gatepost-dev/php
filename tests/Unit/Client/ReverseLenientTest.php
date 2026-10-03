<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ReverseResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of a reverse response that never fail a call: only the parts that spec/client.md
 * lists raise unexpected_response, so any other part of the wrong type reads as null.
 */
final class ReverseLenientTest extends TestCase
{
    #[Test]
    public function readsARadiusThatIsMissingOrNotANumberAsNull(): void
    {
        $absent = ReverseResult::fromResponse(['found' => false]);
        $text = ReverseResult::fromResponse(['found' => false, 'radius_m' => '25']);
        $zero = ReverseResult::fromResponse(['found' => false, 'radius_m' => 0]);

        self::assertNull($absent->radiusM);
        self::assertNull($text->radiusM);
        self::assertSame(0.0, $zero->radiusM);
    }

    #[Test]
    public function keepsTheAreaAndTheDistrictAsTheGatewaySentThem(): void
    {
        $response = [
            'found' => true,
            'area' => 'not a postcode',
            'district' => 7,
            'state' => ['FC'],
        ];

        $result = ReverseResult::fromResponse($response);

        self::assertSame('not a postcode', $result->area);
        self::assertNull($result->district);
        self::assertNull($result->state);
    }

    #[Test]
    public function readsAUnitThatIsNullAsNoUnit(): void
    {
        $result = ReverseResult::fromResponse(['found' => true, 'unit' => null]);

        self::assertNull($result->unit);
    }
}
