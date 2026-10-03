<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\LookupResult;
use Gatepost\Postcode\Client\PostcodeException;
use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How the client reads a lookup response. Level 1 is what Gatepost has seen. Levels 2 and 3 use
 * the synthetic fixtures in NIPOST's documented shape, and levels 4 and 5 use NIPOST's field
 * names only.
 */
final class LookupResultTest extends TestCase
{
    #[Test]
    public function readsALevel1BodyAndKeepsThePostcodeOfTheCaller(): void
    {
        $response = ['postcode' => 'FC01Z99ZZ01', 'valid' => true, 'status' => 'valid'];

        $result = LookupResult::fromResponse($response, self::unit(), 1);

        self::assertSame('FC-01-Z99-ZZ-01', $result->postcode->canonical);
        self::assertTrue($result->valid);
        self::assertSame('valid', $result->status);
        self::assertSame(1, $result->levelReceived);
        self::assertNull($result->administrativeAddress);
        self::assertNull($result->recentHouseAddress);
        self::assertNull($result->buildingUseStatus);
    }

    #[Test]
    public function readsTheAddressOfTheDocumentedLevel3Shape(): void
    {
        $result = FixtureGateway::client()->lookup(FixtureGateway::UNIT, level: 3);

        self::assertSame(3, $result->levelRequested);
        self::assertSame(3, $result->levelReceived);
        self::assertNotNull($result->administrativeAddress);
        self::assertSame('SYNTHETIC LGA', $result->administrativeAddress->lgaName);
        self::assertSame('NORTH CENTRAL', $result->administrativeAddress->zone);
        self::assertSame('1 SYNTHETIC STREET, SYNTHETIC LOCALITY', $result->recentHouseAddress);
        self::assertSame('residential', $result->buildingUseStatus);
        self::assertNull($result->status);
    }

    #[Test]
    public function ignoresEachFieldThatTheSpecDoesNotUse(): void
    {
        $body = ['valid' => true, 'status' => 'valid', 'verified' => false, 'new_field' => [1]];

        $result = LookupResult::fromResponse($body, self::unit(), 1);

        self::assertTrue($result->valid);
        self::assertSame(1, $result->levelReceived);
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function levelFields(): array
    {
        $address = ['state_name' => 'S', 'lga_name' => 'L', 'locality_name' => 'C', 'zone' => 'Z'];

        return [
            'no field of a higher level' => [[], 1],
            'an administrative address' => [['administrative_address' => $address], 2],
            'a recent house address' => [['recent_house_address' => ['recent' => 'SYNTHETIC']], 2],
            'a building use status' => [['building_use_status' => 'residential'], 3],
            'other building information' => [['other_building_info' => ['floors' => 2]], 4],
            'a point geometry' => [['point_geometry' => ['type' => 'Point']], 5],
            'the fields of levels 2 and 3' => [
                ['recent_house_address' => ['recent' => 'SYNTHETIC'], 'building_use_status' => 'x'],
                3,
            ],
            'the fields of levels 3 and 4' => [
                ['building_use_status' => 'residential', 'other_building_info' => ['floors' => 2]],
                4,
            ],
            'the fields of levels 4 and 5' => [
                ['other_building_info' => ['floors' => 2], 'point_geometry' => ['type' => 'Point']],
                5,
            ],
            'a field of level 2 that is null' => [['administrative_address' => null], 1],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('levelFields')]
    public function readsTheLevelReceivedFromTheMostPreciseField(array $fields, int $level): void
    {
        $result = LookupResult::fromResponse(['valid' => true, ...$fields], self::unit(), 5);

        self::assertSame($level, $result->levelReceived);
        self::assertSame(5, $result->levelRequested);
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function statuses(): array
    {
        return [
            'valid' => ['valid', 'valid'],
            'not_found' => ['not_found', 'not_found'],
            'invalid' => ['invalid', 'invalid'],
            'restricted' => ['restricted', 'restricted'],
            'a word that the spec does not list' => ['pending', 'pending'],
            'an empty word' => ['', ''],
            'null' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function keepsAnyStatusTextAsItIs(mixed $status, ?string $expected): void
    {
        $body = ['valid' => false, 'status' => $status, 'verified' => false, 'new_field' => [1]];

        $result = LookupResult::fromResponse($body, self::unit(), 1);

        self::assertFalse($result->valid);
        self::assertSame($expected, $result->status);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadableBodies(): array
    {
        return [
            'a list in place of an object' => [[['valid' => true]]],
            'no valid field' => [['status' => 'valid']],
            'valid as text' => [['valid' => 'true']],
            'a status that is a number' => [['valid' => true, 'status' => 5]],
            'a status that is a list' => [['valid' => true, 'status' => ['valid']]],
            'valid as null' => [['valid' => null]],
        ];
    }

    #[Test]
    #[DataProvider('unreadableBodies')]
    public function failsWithUnexpectedResponseWhenValidOrStatusIsUnreadable(mixed $response): void
    {
        try {
            LookupResult::fromResponse($response, self::unit(), 1);
            self::fail('The body was read.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::UnexpectedResponse, $error->errorCode());
            self::assertSame(200, $error->status());
        }
    }

    private static function unit(): Postcode
    {
        $parsed = Postcode::parse(FixtureGateway::UNIT);
        self::assertTrue($parsed->isOk());

        return $parsed->value;
    }
}
