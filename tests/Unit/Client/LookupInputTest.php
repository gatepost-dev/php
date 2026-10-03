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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The input that a lookup refuses before it sends a request: a code that fails the format check,
 * and a level outside 1 to 5. Both are invalid_input errors.
 */
final class LookupInputTest extends TestCase
{
    /**
     * @return array<string, array{string|Postcode}>
     */
    public static function badCodes(): array
    {
        $district = Postcode::parse('FC-01-Z99', allowPartial: true)->value;
        self::assertNotNull($district);

        return [
            'a unit of one digit' => ['FC-01-Z99-ZZ-1'],
            'empty text' => [''],
            'a legacy postcode' => ['900108'],
            'a partial postcode as text' => ['FC01Z99'],
            'a partial postcode that parse accepted' => [$district],
        ];
    }

    #[Test]
    #[DataProvider('badCodes')]
    public function sendsNoRequestForACodeThatFailsTheCheck(string|Postcode $code): void
    {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        try {
            $client->lookup($code);
            self::fail('The lookup did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::InvalidInput, $error->errorCode());
            self::assertNull($error->status());
        }
        self::assertSame([], $gateway->requests);
    }

    #[Test]
    public function namesTheReasonOfTheFormatCheckInTheMessage(): void
    {
        $this->expectExceptionMessage(
            'The postcode failed the format check with bad_length. Call Postcode::parse() to show '
                . 'the user the reason.',
        );

        FixtureGateway::client()->lookup('FC-01-Z99-ZZ-1');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function badLevels(): array
    {
        return ['level 0' => [0], 'level 6' => [6], 'a negative level' => [-1]];
    }

    #[Test]
    #[DataProvider('badLevels')]
    public function sendsNoRequestForALevelOutsideOneToFive(int $level): void
    {
        $gateway = new FixtureGateway();
        $client = new PostcodeClient($gateway, new HttpFactory());

        try {
            $client->lookup(FixtureGateway::UNIT, $level);
            self::fail('The lookup did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::InvalidInput, $error->errorCode());
            self::assertNull($error->status());
        }
        self::assertSame([], $gateway->requests);
    }
}
