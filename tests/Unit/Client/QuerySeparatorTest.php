<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\PostcodeClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Some hosts set the ini value arg_separator.output to &amp;, which http_build_query() reads when
 * it gets no separator. The gateway needs a plain & between the parameters.
 */
final class QuerySeparatorTest extends TestCase
{
    private string|false $separator;

    protected function setUp(): void
    {
        $this->separator = \ini_set('arg_separator.output', '&amp;');
    }

    protected function tearDown(): void
    {
        if ($this->separator !== false) {
            \ini_set('arg_separator.output', $this->separator);
        }
    }

    #[Test]
    public function joinsTheParametersOfALookupWithAnAmpersand(): void
    {
        $gateway = new ScriptedTransport([FixtureGateway::fixture('lookup/valid-level-2')]);
        $client = new PostcodeClient($gateway, new HttpFactory());

        $client->lookup(FixtureGateway::UNIT, level: 2);

        self::assertSame(
            'code=FC-01-Z99-ZZ-01&level=2',
            $gateway->requests[0]->getUri()->getQuery(),
        );
    }

    #[Test]
    public function joinsTheParametersOfAReverseCallWithAnAmpersand(): void
    {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        $client->reverse(9.0, 7.0, 25.0);

        self::assertSame(
            'lat=9&lng=7&max_distance_m=25',
            $gateway->requests[0]->getUri()->getQuery(),
        );
    }
}
