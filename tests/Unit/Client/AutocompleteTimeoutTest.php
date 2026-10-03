<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\Internal\Sender;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;

/**
 * The timeout of autocomplete: 15 s by default, because a user waits for each completion, and no
 * retry after a timeout, because the next keystroke replaces the call.
 */
final class AutocompleteTimeoutTest extends TestCase
{
    #[Test]
    public function waitsFifteenSecondsByDefaultAndTheCallersTimeoutWhenGiven(): void
    {
        $factory = new HttpFactory();

        self::assertSame(15000, self::timeoutOfAutocomplete(new PostcodeClient(
            new FixtureGateway(),
            $factory,
        )));
        self::assertSame(200, self::timeoutOfAutocomplete(new PostcodeClient(
            new FixtureGateway(),
            $factory,
            timeoutMs: 200,
        )));
    }

    #[Test]
    public function doesNotRetryATimeout(): void
    {
        $transport = new class implements ClientInterface {
            public int $attempts = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->attempts;
                \usleep(30_000);

                throw new ConnectException('The connection timed out.', $request);
            }
        };
        $client = new PostcodeClient($transport, new HttpFactory(), timeoutMs: 20);

        try {
            $client->autocomplete('F');
            self::fail('The call did not fail.');
        } catch (PostcodeException $error) {
            self::assertSame(ErrorCode::Timeout, $error->errorCode());
        }
        self::assertSame(1, $transport->attempts);
    }

    /**
     * Reads the timeout of the sender that autocomplete uses. A timeout takes real time, and the
     * default one is 15 s, so a test cannot wait for it.
     */
    private static function timeoutOfAutocomplete(PostcodeClient $client): int
    {
        $sender = (new ReflectionProperty(PostcodeClient::class, 'autocompleteSender'))
            ->getValue($client);
        self::assertInstanceOf(Sender::class, $sender);
        $timeout = (new ReflectionProperty(Sender::class, 'timeoutMs'))->getValue($sender);
        self::assertIsInt($timeout);

        return $timeout;
    }
}
