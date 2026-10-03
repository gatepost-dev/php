<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ERR-1 maps each HTTP status to one error code through the table in spec/client.md. The contract
 * scenarios reach some rows of the table. This test reaches each row.
 */
final class PostcodeExceptionTest extends TestCase
{
    /**
     * @return array<string, array{int, ?string, ErrorCode}>
     */
    public static function statuses(): array
    {
        return [
            '401 with no key' => [401, 'auth_required', ErrorCode::Unauthorized],
            '401 with a wrong key' => [401, 'invalid_api_key', ErrorCode::Unauthorized],
            'status 402' => [402, 'insufficient_credits', ErrorCode::InsufficientCredits],
            '403 for an origin' => [403, 'origin_not_allowed', ErrorCode::OriginNotAllowed],
            '403 for a level' => [403, 'level_not_granted', ErrorCode::Forbidden],
            '403 with no body' => [403, null, ErrorCode::Forbidden],
            'status 429' => [429, 'rate_limited', ErrorCode::RateLimited],
            'status 502' => [502, null, ErrorCode::ServerError],
            'status 503' => [503, null, ErrorCode::ServerError],
            'status 504' => [504, null, ErrorCode::ServerError],
            'status 400' => [400, 'invalid_request', ErrorCode::InvalidInput],
            'status 404' => [404, 'not_found', ErrorCode::InvalidInput],
            'status 499' => [499, null, ErrorCode::InvalidInput],
            'status 500' => [500, 'internal', ErrorCode::ServerError],
            'status 501' => [501, null, ErrorCode::ServerError],
            'status 505' => [505, null, ErrorCode::ServerError],
            'status 204' => [204, null, ErrorCode::ServerError],
            'status 301' => [301, null, ErrorCode::ServerError],
        ];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function mapsEachStatusToOneErrorCode(
        int $status,
        ?string $apiCode,
        ErrorCode $code,
    ): void {
        $error = PostcodeException::fromStatus($status, $apiCode, null);

        self::assertSame($code, $error->errorCode());
        self::assertSame($status, $error->status());
        self::assertSame($apiCode, $error->apiCode());
    }

    /**
     * @return array<string, array{PostcodeException, string}>
     */
    public static function messages(): array
    {
        $failure = new RuntimeException('A synthetic failure.');

        return [
            'status 401' => [
                PostcodeException::fromStatus(401, 'auth_required', null),
                'The gateway refused the API key (HTTP 401). Check the key that the client sends.',
            ],
            'status 402' => [
                PostcodeException::fromStatus(402, 'insufficient_credits', null),
                'The account has no credits for this lookup level (HTTP 402). Add credits, or ask '
                    . 'for a lower level.',
            ],
            'status 403 for an origin' => [
                PostcodeException::fromStatus(403, 'origin_not_allowed', null),
                'The key does not allow the origin of this request (HTTP 403). Add the origin to '
                    . 'the key in the NIPOST dashboard.',
            ],
            'status 403 for a level' => [
                PostcodeException::fromStatus(403, 'level_not_granted', null),
                'The key does not allow this request (HTTP 403). Check the scope and the lookup '
                    . 'level of the key.',
            ],
            'status 429 with no Retry-After' => [
                PostcodeException::fromStatus(429, 'rate_limited', null),
                'The gateway refused the request because the key sent too many (HTTP 429). Wait, '
                    . 'then try again.',
            ],
            'status 429 that asks for 11.4 seconds' => [
                PostcodeException::fromStatus(429, 'rate_limited', 11_400),
                'The gateway refused the request because the key sent too many (HTTP 429). Wait '
                    . '12 s, then try again.',
            ],
            'status 400' => [
                PostcodeException::fromStatus(400, 'invalid_request', null),
                'The gateway rejected the request (HTTP 400). Check the input of the call.',
            ],
            'status 503' => [
                PostcodeException::fromStatus(503, null, null),
                'The gateway failed (HTTP 503). Try again later.',
            ],
            'an unreadable response' => [
                PostcodeException::unreadable(200),
                'The gateway sent a response that the client cannot read (HTTP 200). Try again '
                    . 'later, and report it if it happens again.',
            ],
            'a failed connection' => [
                PostcodeException::networkError($failure),
                'The client could not reach the gateway. Check the network and the base URL.',
            ],
            'a timeout' => [
                PostcodeException::timeout(8000, $failure),
                'The gateway sent no response within 8000 ms. Try again later.',
            ],
        ];
    }

    #[Test]
    #[DataProvider('messages')]
    public function saysWhatHappenedAndWhatToDo(PostcodeException $error, string $message): void
    {
        self::assertSame($message, $error->getMessage());
        self::assertSame(0, $error->getCode());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function retryAfterWaits(): array
    {
        return [
            'zero' => [0, 'Wait, then try again.'],
            'one second' => [1000, 'Wait 1 s, then try again.'],
            'one millisecond over one second' => [1001, 'Wait 2 s, then try again.'],
            'one millisecond under one second' => [999, 'Wait 1 s, then try again.'],
            'a fraction of a second' => [11_400, 'Wait 12 s, then try again.'],
        ];
    }

    // The error is made inside the test, not in a data provider, so a mutation test that changes
    // the message code also changes the error that this test reads.
    #[Test]
    #[DataProvider('retryAfterWaits')]
    public function tellsTheUserHowLongToWaitAfterATooManyRequestsError(
        int $retryAfterMs,
        string $wait,
    ): void {
        $error = PostcodeException::fromStatus(429, 'rate_limited', $retryAfterMs);

        self::assertSame(
            'The gateway refused the request because the key sent too many (HTTP 429). ' . $wait,
            $error->getMessage(),
        );
    }

    #[Test]
    public function keepsTheStatusOfAnUnreadableResponse(): void
    {
        $error = PostcodeException::unreadable(200);

        self::assertSame(ErrorCode::UnexpectedResponse, $error->errorCode());
        self::assertSame(200, $error->status());
        self::assertNull($error->apiCode());
        self::assertNull($error->retryAfterMs());
    }

    #[Test]
    public function hasOneCaseForEachCodeOfTheErrorTableInTheSpec(): void
    {
        $client = \file_get_contents(\dirname(__DIR__, 3) . '/spec/client.md');
        self::assertIsString($client);
        $errors = \explode("\n## ", \explode("\n## Errors\n", $client)[1])[0];
        \preg_match_all('/^\| [^|]+ \| `([a-z_]+)` \|/m', $errors, $codes);

        self::assertSame(
            \array_values(\array_unique($codes[1])),
            \array_map(static fn(ErrorCode $code): string => $code->value, ErrorCode::cases()),
        );
    }
}
