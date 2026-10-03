<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Sender;
use Gatepost\Postcode\Client\Internal\SystemTimer;
use Gatepost\Postcode\Postcode;
use Gatepost\Postcode\Precision;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Calls NIPOST's postcode gateway with your own API key. Each call blocks until it has a result
 * or a PostcodeException. A call that fails for a reason that can pass, such as a 503, is tried
 * again, up to maxRetries times.
 *
 * Unofficial. Not made or endorsed by NIPOST.
 *
 * ```php
 * $client = new PostcodeClient(
 *     new \GuzzleHttp\Client(['timeout' => 8]),
 *     new \GuzzleHttp\Psr7\HttpFactory(),
 *     apiKey: \getenv('NIPOST_API_KEY') ?: null,
 * );
 * ```
 */
final class PostcodeClient
{
    private const DEFAULT_BASE_URL = 'https://api.postcode.gov.ng';

    private readonly string $baseUrl;

    private readonly Sender $sender;

    /**
     * @param ClientInterface         $transport      A PSR-18 HTTP client. Set its timeout to
     *                                                timeoutMs, because PSR-18 has no timeout.
     * @param RequestFactoryInterface $requestFactory A PSR-17 factory for the requests.
     * @param ?string                 $apiKey         The key for the X-API-Key header. With null,
     *                                                the client sends no key, and the gateway
     *                                                answers 401.
     * @param string                  $baseUrl        The gateway's address.
     * @param int                     $timeoutMs      The longest wait for one attempt. A failed
     *                                                attempt that took this long is a timeout.
     * @param int                     $maxRetries     The most retries after the first attempt.
     *
     * @throws InvalidArgumentException When an option is out of range, or the key is empty.
     *
     * Callers name each option, and TELL-3 counts no named or defaulted parameter, so PHPMD's
     * count of parameters does not apply here.
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        ClientInterface $transport,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeoutMs = 8000,
        int $maxRetries = 2,
    ) {
        if ($apiKey === '') {
            throw new InvalidArgumentException('apiKey is empty. Pass null to send no key.');
        }
        if ($timeoutMs <= 0) {
            throw new InvalidArgumentException("timeoutMs is {$timeoutMs}. Use 1 or more.");
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentException("maxRetries is {$maxRetries}. Use 0 or more.");
        }
        $this->baseUrl = \rtrim($baseUrl, '/');
        $this->sender = new Sender($transport, $timeoutMs, $maxRetries, new SystemTimer());
    }

    /**
     * Asks the gateway what it knows about a postcode. The client checks the code with
     * Postcode::parse() first, and sends no request for a code that fails. Levels above 1 use
     * credits, and a key holds a level grant. A level above the grant fails with Forbidden.
     * The shapes of levels 2 to 5 follow NIPOST's docs. Gatepost has seen only level 1.
     *
     * ```php
     * $result = $client->lookup('fc 01 z99 zz 01');
     * $result->valid; // true
     * echo $result->postcode->canonical; // FC-01-Z99-ZZ-01
     * ```
     *
     * @param string|Postcode $code  A full postcode, as text or as a parsed postcode.
     * @param int             $level The lookup level, from 1 to 5.
     *
     * @throws PostcodeException For a code that fails the check, for a level outside 1 to 5,
     *                           and for each failure of the gateway. The client sends no
     *                           request for bad input.
     */
    public function lookup(string|Postcode $code, int $level = 1): LookupResult
    {
        if ($level < 1 || $level > 5) {
            throw PostcodeException::invalidInput("The lookup level is {$level}. Use 1 to 5.");
        }
        $postcode = self::fullPostcode($code);
        $query = ['code' => $postcode->canonical, 'level' => $level];
        $response = $this->send('/v1/lookup', $query, retryTimeouts: true);

        return LookupResult::fromResponse($response, $postcode, $level);
    }

    /**
     * @throws PostcodeException
     */
    private static function fullPostcode(string|Postcode $code): Postcode
    {
        if (\is_string($code)) {
            $parsed = Postcode::parse($code);
            if (!$parsed->isOk()) {
                throw PostcodeException::invalidInput(
                    "The postcode failed the format check with {$parsed->error->code->value}. "
                        . 'Call Postcode::parse() to show the user the reason.',
                );
            }
            $code = $parsed->value;
        }
        if ($code->precision !== Precision::Unit) {
            throw PostcodeException::invalidInput(
                'The postcode stops before the unit. A lookup needs all five segments.',
            );
        }

        return $code;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @throws PostcodeException
     */
    private function send(string $path, array $query, bool $retryTimeouts): mixed
    {
        $url = $this->baseUrl . $path . '?' . \http_build_query($query);
        $request = $this->requestFactory->createRequest('GET', $url);
        if ($this->apiKey !== null) {
            $request = $request->withHeader('X-API-Key', $this->apiKey);
        }

        return $this->sender->send($request, $retryTimeouts);
    }
}
