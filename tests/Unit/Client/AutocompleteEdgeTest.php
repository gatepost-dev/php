<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\AutocompleteResult;
use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The edges of autocomplete: the message for each kind of refused text, a JSON object where the
 * list of suggestions belongs, and a code that ends outside the active segment.
 */
final class AutocompleteEdgeTest extends TestCase
{
    #[Test]
    public function saysThatTextOverTheInputLimitIsTooLong(): void
    {
        $client = new PostcodeClient(new FixtureGateway(), new HttpFactory());

        try {
            $client->autocomplete('FC' . \str_repeat(' ', 70));
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::InvalidInput, $error->errorCode());
            self::assertStringContainsString('64', $error->getMessage());
            self::assertStringNotContainsString('1 to 11', $error->getMessage());
        }
    }

    #[Test]
    public function saysThatTextWithBytesThatAreNotUtf8IsNotText(): void
    {
        $client = new PostcodeClient(new FixtureGateway(), new HttpFactory());

        try {
            $client->autocomplete("FC\xFF");
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertStringContainsString('UTF-8', $error->getMessage());
        }
    }

    #[Test]
    public function failsWithUnexpectedResponseForSuggestionsThatAreAJsonObject(): void
    {
        $body = '{"data":{"segment":"state","suggestions":{}}}';
        $transport = new ScriptedTransport([new Response(200, [], $body)]);
        $client = new PostcodeClient($transport, new HttpFactory());

        $this->expectExceptionObject(PostcodeException::unreadable(200));

        $client->autocomplete('F');
    }

    #[Test]
    public function givesNullForACodeThatEndsOutsideTheActiveSegment(): void
    {
        $district = ['segment' => 'district', 'suggestions' => [['code' => 'Z99ZZ']]];
        $state = ['segment' => 'state', 'suggestions' => [['code' => 'FC01']]];

        // FC01Z99ZZ and FC01 are postcodes, but of the area and of the LGA, not of the segment.
        $inDistrict = AutocompleteResult::fromResponse($district, 'FC01Z');
        $inState = AutocompleteResult::fromResponse($state, 'F');

        self::assertNull($inDistrict->suggestions[0]->postcode);
        self::assertNull($inState->suggestions[0]->postcode);
    }
}
