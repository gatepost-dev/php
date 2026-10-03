<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The request of a reverse search: the query that the client writes, and the input that it
 * refuses before it sends one (spec/client.md, Requests).
 */
final class ReverseInputTest extends TestCase
{
    /**
     * @return array<string, array{float, float, ?float, string}>
     */
    public static function points(): array
    {
        return [
            'whole degrees' => [9.0, 7.0, null, 'lat=9&lng=7'],
            'a radius' => [9.001, 7.001, 25.0, 'lat=9.001&lng=7.001&max_distance_m=25'],
            'a small number with no exponent' => [0.00001, -0.5, null, 'lat=0.00001&lng=-0.5'],
            'a negative zero' => [-0.0, 0.0, 0.0, 'lat=0&lng=0&max_distance_m=0'],
            'the limits' => [-90.0, 180.0, 250.0, 'lat=-90&lng=180&max_distance_m=250'],
            'the other limits' => [90.0, -180.0, null, 'lat=90&lng=-180'],
        ];
    }

    #[Test]
    #[DataProvider('points')]
    public function writesEachNumberInItsShortestDecimalForm(
        float $lat,
        float $lng,
        ?float $maxDistanceM,
        string $query,
    ): void {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        $client->reverse($lat, $lng, $maxDistanceM);

        self::assertSame('/v1/search/reverse', $gateway->requests[0]->getUri()->getPath());
        self::assertSame($query, $gateway->requests[0]->getUri()->getQuery());
    }

    /**
     * @return array<string, array{float, float, ?float}>
     */
    public static function badInputs(): array
    {
        return [
            'a latitude that is NAN' => [\NAN, 7.0, null],
            'a latitude that is infinite' => [\INF, 7.0, null],
            'a latitude above 90' => [90.0000001, 7.0, null],
            'a latitude below -90' => [-90.0000001, 7.0, null],
            'a longitude that is NAN' => [9.0, \NAN, null],
            'a longitude that is infinite' => [9.0, -\INF, null],
            'a longitude above 180' => [9.0, 180.0000001, null],
            'a longitude below -180' => [9.0, -180.0000001, null],
            'a radius that is NAN' => [9.0, 7.0, \NAN],
            'a radius below 0' => [9.0, 7.0, -0.0000001],
            'a radius above 250' => [9.0, 7.0, 250.0000001],
        ];
    }

    #[Test]
    #[DataProvider('badInputs')]
    public function sendsNoRequestForANumberOutsideItsRange(
        float $lat,
        float $lng,
        ?float $maxDistanceM,
    ): void {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        try {
            $client->reverse($lat, $lng, $maxDistanceM);
            self::fail('The search did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::InvalidInput, $error->errorCode());
            self::assertNull($error->status());
        }
        self::assertSame([], $gateway->requests);
    }
}
