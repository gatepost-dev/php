<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\Internal\Sender;
use Gatepost\Postcode\Client\PostcodeException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * The harness for the tests of the retries, waits and timeouts of spec/client.md. A fake clock
 * moves only when the sender waits or a scripted connection fails, so no test sleeps (T-5). The
 * contract scenarios run the same rules against the mock server with the real clock.
 */
abstract class SenderTestCase extends TestCase
{
    protected const VALID = ['data' => ['postcode' => 'FC-01-Z99-ZZ-01', 'valid' => true]];
    protected const TIMEOUT_MS = 8000;

    protected FakeTimer $timer;

    protected function setUp(): void
    {
        $this->timer = new FakeTimer();
    }

    /**
     * Sends one call with the default options of the client, unless the test names others.
     */
    protected function send(
        ScriptedTransport $transport,
        int $maxRetries = 2,
        bool $retryTimeouts = true,
        ?FakeTimer $timer = null,
    ): mixed {
        $sender = new Sender($transport, self::TIMEOUT_MS, $maxRetries, $timer ?? $this->timer);
        $request = new Request('GET', 'https://gateway.invalid/v1/lookup');

        return $sender->send($request, $retryTimeouts);
    }

    /**
     * Sends one call that must fail, and returns its error.
     */
    protected function failure(
        ScriptedTransport $transport,
        int $maxRetries = 2,
        bool $retryTimeouts = true,
        ?FakeTimer $timer = null,
    ): PostcodeException {
        try {
            $this->send($transport, $maxRetries, $retryTimeouts, $timer);
        } catch (PostcodeException $error) {
            return $error;
        }

        self::fail('The call did not fail.');
    }
}
