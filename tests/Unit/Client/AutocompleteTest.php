<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\AutocompleteResult;
use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use Gatepost\Postcode\Precision;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The text that autocomplete sends, and the postcode that the client builds for each suggestion.
 * The gateway sends the value of one segment only, and it answers no empty q.
 */
final class AutocompleteTest extends TestCase
{
    #[Test]
    public function sendsNormalisedTextAndBuildsThePostcodeOfEachSuggestion(): void
    {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        $result = $client->autocomplete('fc 01-z');

        self::assertSame('/v1/search/autocomplete', $gateway->requests[0]->getUri()->getPath());
        self::assertSame('q=FC01Z', $gateway->requests[0]->getUri()->getQuery());
        self::assertSame(Precision::District, $result->segment);
        self::assertCount(1, $result->suggestions);
        self::assertSame('Z99', $result->suggestions[0]->code);
        self::assertNull($result->suggestions[0]->label);
        self::assertSame('FC-01-Z99', $result->suggestions[0]->postcode?->canonical);
    }

    #[Test]
    public function buildsAStatePostcodeFromTheFirstLetter(): void
    {
        $response = ['segment' => 'state', 'suggestions' => [['code' => 'FC']]];

        $result = AutocompleteResult::fromResponse($response, 'F');

        self::assertSame('FC', $result->suggestions[0]->postcode?->canonical);
    }

    #[Test]
    public function keepsALabelAndGivesNullForACodeThatMakesNoPostcode(): void
    {
        $response = [
            'segment' => 'district',
            'suggestions' => [['code' => 'Z99', 'label' => 'SYNTHETIC'], ['code' => 'ZZZZ']],
            'limit' => 20,
        ];

        $result = AutocompleteResult::fromResponse($response, 'FC01Z');

        self::assertSame('SYNTHETIC', $result->suggestions[0]->label);
        self::assertSame('FC-01-Z99', $result->suggestions[0]->postcode?->canonical);
        self::assertNull($result->suggestions[1]->postcode);
    }

    #[Test]
    public function readsALabelThatIsNotTextAsNull(): void
    {
        $response = ['segment' => 'state', 'suggestions' => [['code' => 'FC', 'label' => 7]]];

        $result = AutocompleteResult::fromResponse($response, 'F');

        self::assertNull($result->suggestions[0]->label);
    }

    #[Test]
    public function readsAnEmptyListAsNoSuggestions(): void
    {
        $response = ['segment' => 'area', 'suggestions' => []];

        $result = AutocompleteResult::fromResponse($response, 'FC01Z99Z');

        self::assertSame(Precision::Area, $result->segment);
        self::assertSame([], $result->suggestions);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badTexts(): array
    {
        return [
            'empty text' => [''],
            'only separators' => [' - '],
            'a character that no postcode has' => ['FC!'],
            'more than 11 characters' => ['FC01Z99ZZ011'],
            'separators that push the text over the input limit' => ['FC' . \str_repeat(' ', 70)],
            'bytes that are not UTF-8' => ["FC\xFF"],
        ];
    }

    #[Test]
    #[DataProvider('badTexts')]
    public function sendsNoRequestForTextThatFailsTheCheck(string $q): void
    {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        try {
            $client->autocomplete($q);
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::InvalidInput, $error->errorCode());
            self::assertNull($error->status());
        }
        self::assertSame([], $gateway->requests);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadableBodies(): array
    {
        return [
            'a segment that is not one of the five' => [['segment' => 'zone', 'suggestions' => []]],
            'a segment that is a number' => [['segment' => 3, 'suggestions' => []]],
            'no suggestions field' => [['segment' => 'state']],
            'suggestions as an object' => [['segment' => 'state', 'suggestions' => ['a' => 1]]],
            'a code that is a number' => [['segment' => 'lga', 'suggestions' => [['code' => 1]]]],
            'a suggestion with no code' => [
                ['segment' => 'lga', 'suggestions' => [['label' => 'a']]],
            ],
            'a suggestion that is text' => [['segment' => 'lga', 'suggestions' => ['01']]],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    #[Test]
    #[DataProvider('unreadableBodies')]
    public function failsWithUnexpectedResponseWhenAKnownFieldHasTheWrongType(array $response): void
    {
        $this->expectExceptionObject(PostcodeException::unreadable(200));

        AutocompleteResult::fromResponse($response, 'FC01');
    }
}
