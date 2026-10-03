<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\Confidence;
use Gatepost\Postcode\Client\ReverseResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How the client reads the three shapes of a reverse response: a unit, an area with no unit in the
 * radius, and no postcode at all.
 */
final class ReverseTest extends TestCase
{
    #[Test]
    public function readsTheUnitNearestToThePoint(): void
    {
        $result = FixtureGateway::client()->reverse(9.0, 7.0);

        self::assertTrue($result->found);
        self::assertSame(25.0, $result->radiusM);
        self::assertNotNull($result->unit);
        self::assertSame(FixtureGateway::UNIT, $result->unit->postcode->canonical);
        self::assertSame(4.2, $result->unit->distanceM);
        self::assertSame(Confidence::Low, $result->unit->confidence);
        self::assertNull($result->unit->stateName);
        self::assertNull($result->unit->lgaName);
        self::assertNull($result->unit->localityName);
        self::assertNull($result->unit->address);
        self::assertSame('FC-01-Z99-ZZ', $result->area);
        self::assertSame('FC-01-Z99', $result->district);
        self::assertSame('FC', $result->state);
    }

    #[Test]
    public function findsAnAreaWithNoUnitWithinTheRadius(): void
    {
        $result = FixtureGateway::client()->reverse(9.001, 7.001, maxDistanceM: 25);

        self::assertTrue($result->found);
        self::assertNull($result->unit);
        self::assertSame('FC-01-Z99-ZZ', $result->area);
    }

    #[Test]
    public function returnsFoundFalseForAPointWithNoPostcode(): void
    {
        $result = FixtureGateway::client()->reverse(0.0, 0.0);

        self::assertFalse($result->found);
        self::assertNull($result->unit);
        self::assertNull($result->area);
        self::assertNull($result->district);
        self::assertNull($result->state);
    }

    #[Test]
    public function readsTheLevelTwoNamesOfAUnit(): void
    {
        $unit = [
            'postcode' => FixtureGateway::UNIT,
            'distance_m' => 4,
            'confidence' => 'high',
            'state_name' => 'SYNTHETIC STATE',
            'lga_name' => 'SYNTHETIC LGA',
            'locality_name' => 'SYNTHETIC LOCALITY',
            'address' => 'SYNTHETIC ADDRESS',
        ];

        $result = ReverseResult::fromResponse(['found' => true, 'radius_m' => 25, 'unit' => $unit]);

        self::assertNotNull($result->unit);
        self::assertSame(Confidence::High, $result->unit->confidence);
        self::assertSame(4.0, $result->unit->distanceM);
        self::assertSame('SYNTHETIC STATE', $result->unit->stateName);
        self::assertSame('SYNTHETIC LGA', $result->unit->lgaName);
        self::assertSame('SYNTHETIC LOCALITY', $result->unit->localityName);
        self::assertSame('SYNTHETIC ADDRESS', $result->unit->address);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function confidences(): array
    {
        return [
            'a word that the spec does not list' => ['very_high'],
            'a number' => [3],
            'no confidence' => [null],
        ];
    }

    #[Test]
    #[DataProvider('confidences')]
    public function readsAnUnknownConfidenceAsLow(mixed $confidence): void
    {
        $unit = ['postcode' => FixtureGateway::UNIT, 'distance_m' => 4];
        $unit['confidence'] = $confidence;

        $result = ReverseResult::fromResponse(['found' => true, 'radius_m' => 25, 'unit' => $unit]);

        self::assertNotNull($result->unit);
        self::assertSame(Confidence::Low, $result->unit->confidence);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadableBodies(): array
    {
        $found = ['found' => true, 'radius_m' => 25];
        $unit = ['postcode' => FixtureGateway::UNIT, 'distance_m' => 4.2, 'confidence' => 'high'];

        return [
            'no found field' => [['radius_m' => 25]],
            'a found that is text' => [['found' => 'true']],
            'a unit with the postcode of an area' => [
                $found + ['unit' => ['postcode' => 'FC-01-Z99-ZZ'] + $unit],
            ],
            'a unit with a postcode that does not parse' => [
                $found + ['unit' => ['postcode' => 'HELLO'] + $unit],
            ],
            'a unit with no postcode' => [$found + ['unit' => ['postcode' => null] + $unit]],
            'a unit with no distance' => [$found + ['unit' => ['distance_m' => null] + $unit]],
            'a unit with a distance as text' => [
                $found + ['unit' => ['distance_m' => '4'] + $unit],
            ],
            'a unit that is a list' => [$found + ['unit' => [1, 2]]],
            'a unit that is text' => [$found + ['unit' => 'FC-01-Z99-ZZ-01']],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[Test]
    #[DataProvider('unreadableBodies')]
    public function failsWithUnexpectedResponseWhenAListedPartIsBroken(array $response): void
    {
        $this->expectExceptionObject(\Gatepost\Postcode\Client\PostcodeException::unreadable(200));

        ReverseResult::fromResponse($response);
    }
}
