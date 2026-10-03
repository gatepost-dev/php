<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * How the sender reads one response: the data of a 200, and the parts of an error body.
 */
final class SenderTest extends SenderTestCase
{
    #[Test]
    public function returnsTheDataFieldOfTheResponse(): void
    {
        $transport = new ScriptedTransport([ScriptedTransport::json(200, self::VALID)]);

        self::assertSame(self::VALID['data'], $this->send($transport));
        self::assertCount(1, $transport->requests);
    }

    /**
     * @return array<string, array{ResponseInterface}>
     */
    public static function unreadableResponses(): array
    {
        return [
            'a captive portal page' => [new Response(200, [], '<html>Sign in</html>')],
            'a JSON list' => [ScriptedTransport::json(200, [1, 2])],
            'a JSON object with no data field' => [ScriptedTransport::json(200, ['valid' => true])],
            'an empty body' => [new Response(200)],
        ];
    }

    #[Test]
    #[DataProvider('unreadableResponses')]
    public function failsWithUnexpectedResponseOnA200WithNoData(ResponseInterface $response): void
    {
        $error = $this->failure(new ScriptedTransport([$response]));

        self::assertSame(ErrorCode::UnexpectedResponse, $error->errorCode());
        self::assertSame(200, $error->status());
        self::assertSame([], $this->timer->waits);
    }

    /**
     * @return array<string, array{ResponseInterface}>
     */
    public static function unexpectedErrorBodies(): array
    {
        return [
            'plain text' => [new Response(403, [], 'Forbidden')],
            'a JSON list' => [ScriptedTransport::json(403, ['error'])],
            'an error that is text' => [ScriptedTransport::json(403, ['error' => 'denied'])],
            'a code that is a number' => [ScriptedTransport::json(403, ['error' => ['code' => 7]])],
        ];
    }

    #[Test]
    #[DataProvider('unexpectedErrorBodies')]
    public function keepsTheStatusAndNoApiCodeForAnUnexpectedErrorBody(
        ResponseInterface $response,
    ): void {
        $error = $this->failure(new ScriptedTransport([$response]));

        self::assertSame(ErrorCode::Forbidden, $error->errorCode());
        self::assertNull($error->apiCode());
    }

    #[Test]
    public function ignoresRetryAfterOnAStatusOtherThan429(): void
    {
        $transport = new ScriptedTransport([
            ScriptedTransport::error(500, 'internal', ['Retry-After' => '1']),
        ]);

        self::assertNull($this->failure($transport)->retryAfterMs());
    }

    #[Test]
    public function sendsTheSameRequestOnEachAttempt(): void
    {
        $transport = new ScriptedTransport([
            ScriptedTransport::error(503, 'busy'),
            ScriptedTransport::json(200, self::VALID),
        ]);

        $this->send($transport);

        self::assertSame($transport->requests[0], $transport->requests[1]);
    }
}
