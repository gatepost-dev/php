<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\LookupResult;
use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parts of a lookup response outside the closed list of spec/client.md give null when they
 * have the wrong type, because the gateway has charged for the call. The client also ignores the
 * postcode that the body echoes.
 */
final class LookupResultLenientTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function lenientBodies(): array
    {
        return [
            'an address as text' => [['administrative_address' => 'SYNTHETIC'], 2],
            'an address that is a list' => [['administrative_address' => [1]], 2],
            'a recent address as text' => [['recent_house_address' => 'SYNTHETIC'], 2],
            'a recent address that is a list' => [['recent_house_address' => ['x']], 2],
            'a recent text that is a number' => [['recent_house_address' => ['recent' => 7]], 2],
            'a building use status that is a number' => [['building_use_status' => 5], 3],
            'a building use status that is a list' => [['building_use_status' => ['a']], 3],
        ];
    }

    /**
     * The gateway has charged for the call, so a part outside the closed list of client.md
     * gives null and never an error.
     *
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('lenientBodies')]
    public function givesNullForAPartOfTheWrongType(array $fields, int $level): void
    {
        $result = LookupResult::fromResponse(['valid' => true, ...$fields], self::unit(), 3);

        self::assertNull($result->administrativeAddress);
        self::assertNull($result->recentHouseAddress);
        self::assertNull($result->buildingUseStatus);
        self::assertSame($level, $result->levelReceived);
    }

    /**
     * @return array<string, array{array<string, mixed>, ?string, ?string}>
     */
    public static function addressNames(): array
    {
        $names = ['state_name' => 'S', 'lga_name' => 'L', 'locality_name' => 'C', 'zone' => 'Z'];

        return [
            'no zone' => [
                ['state_name' => 'S', 'lga_name' => 'L', 'locality_name' => 'C'],
                'S',
                null,
            ],
            'a zone that is a number' => [[...$names, 'zone' => 5], 'S', null],
            'an empty address' => [[], null, null],
            'a state that is null' => [[...$names, 'state_name' => null], null, 'Z'],
        ];
    }

    /**
     * @param array<string, mixed> $address
     */
    #[Test]
    #[DataProvider('addressNames')]
    public function givesNullForEachNameThatIsMissingOrNotText(
        array $address,
        ?string $state,
        ?string $zone,
    ): void {
        $body = ['valid' => true, 'administrative_address' => $address];

        $result = LookupResult::fromResponse($body, self::unit(), 2);

        self::assertNotNull($result->administrativeAddress);
        self::assertSame($state, $result->administrativeAddress->stateName);
        self::assertSame($zone, $result->administrativeAddress->zone);
    }

    #[Test]
    public function keepsTheCallersPostcodeWhenTheBodyEchoesAnotherOne(): void
    {
        foreach (['FC-01-Z99-ZZ-02', 'hello', 7] as $echo) {
            $body = ['postcode' => $echo, 'valid' => true];

            $result = LookupResult::fromResponse($body, self::unit(), 1);

            self::assertSame('FC-01-Z99-ZZ-01', $result->postcode->canonical);
        }
    }

    private static function unit(): Postcode
    {
        $parsed = Postcode::parse(FixtureGateway::UNIT);
        self::assertTrue($parsed->isOk());

        return $parsed->value;
    }
}
