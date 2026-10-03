<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use Gatepost\Postcode\Postcode;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The request that a lookup sends, and the input that it refuses before it sends one.
 */
final class LookupRequestTest extends TestCase
{
    private const KEY = 'nipost_test_mock_l1';

    #[Test]
    public function sendsTheCanonicalCodeAndTheLevelWithTheKey(): void
    {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory(), apiKey: self::KEY);

        $client->lookup('fc01 z99 zz02', level: 2);

        $request = $gateway->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame(
            'https://api.postcode.gov.ng/v1/lookup?code=FC-01-Z99-ZZ-02&level=2',
            (string) $request->getUri(),
        );
        self::assertSame([self::KEY], $request->getHeader('X-API-Key'));
    }

    #[Test]
    public function sendsNoKeyHeaderWhenTheClientHasNoKey(): void
    {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        $client->lookup(FixtureGateway::UNIT);

        self::assertFalse($gateway->requests[0]->hasHeader('X-API-Key'));
        self::assertSame(
            'code=FC-01-Z99-ZZ-01&level=1',
            $gateway->requests[0]->getUri()->getQuery(),
            'A lookup asks for level 1 unless the caller names a level.',
        );
    }

    #[Test]
    public function joinsTheBaseUrlAndThePathWithOneSlash(): void
    {
        $gateway = new FixtureGateway();
        $baseUrl = 'http://127.0.0.1:4010/';
        $client = new PostcodeClient($gateway, new HttpFactory(), baseUrl: $baseUrl);

        $client->lookup(FixtureGateway::UNIT);

        self::assertSame('/v1/lookup', $gateway->requests[0]->getUri()->getPath());
        self::assertSame('127.0.0.1', $gateway->requests[0]->getUri()->getHost());
    }

    #[Test]
    public function acceptsAParsedPostcode(): void
    {
        $parsed = Postcode::parse(FixtureGateway::UNIT);
        self::assertTrue($parsed->isOk());

        $result = FixtureGateway::client()->lookup($parsed->value);

        self::assertSame($parsed->value, $result->postcode);
    }

    #[Test]
    public function sendsLevel5ToTheGatewayAndReportsItsRefusal(): void
    {
        try {
            FixtureGateway::client()->lookup(FixtureGateway::UNIT, level: 5);
            self::fail('The lookup did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::Forbidden, $error->errorCode());
            self::assertSame('level_not_granted', $error->apiCode());
        }
    }

    #[Test]
    public function returnsAResultWithValidFalseForAnUnknownPostcode(): void
    {
        $result = FixtureGateway::client()->lookup('FC-01-Z99-ZZ-02');

        self::assertFalse($result->valid);
        self::assertSame('FC-01-Z99-ZZ-02', $result->postcode->canonical);
    }
}
