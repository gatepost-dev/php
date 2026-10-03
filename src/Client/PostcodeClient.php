<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Gatepost\Postcode\Client\Internal\Decimal;
use Gatepost\Postcode\Client\Internal\Sender;
use Gatepost\Postcode\Client\Internal\SystemTimer;
use Gatepost\Postcode\Internal\SpecData;
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
    private const AUTOCOMPLETE_TIMEOUT_MS = 15000;
    // The gateway answers no empty q, and a postcode has 11 characters (spec/client.md).
    private const AUTOCOMPLETE_TEXT = '/\A[A-Z0-9]{1,11}\z/';
    private const WITHIN_INPUT_LIMIT = '/\A.{0,' . SpecData::MAX_INPUT_CODE_POINTS . '}\z/su';
    private const MAX_RADIUS_M = 250;

    private readonly string $baseUrl;

    private readonly Sender $sender;

    private readonly Sender $autocompleteSender;

    /**
     * @param ClientInterface         $transport      A PSR-18 HTTP client. Set its timeout to
     *                                                timeoutMs, because PSR-18 has no timeout.
     * @param RequestFactoryInterface $requestFactory A PSR-17 factory for the requests.
     * @param ?string                 $apiKey         The key for the X-API-Key header. With null,
     *                                                the client sends no key, and the gateway
     *                                                answers 401.
     * @param string                  $baseUrl        The gateway's address.
     * @param ?int                    $timeoutMs      The longest wait for one attempt. A failed
     *                                                attempt that took this long is a timeout.
     *                                                With null, it is 8000, and 15000 for
     *                                                autocomplete.
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
        ?int $timeoutMs = null,
        int $maxRetries = 2,
    ) {
        if ($apiKey === '') {
            throw new InvalidArgumentException('apiKey is empty. Pass null to send no key.');
        }
        if ($timeoutMs !== null && $timeoutMs <= 0) {
            throw new InvalidArgumentException("timeoutMs is {$timeoutMs}. Use 1 or more.");
        }
        if ($maxRetries < 0) {
            throw new InvalidArgumentException("maxRetries is {$maxRetries}. Use 0 or more.");
        }
        $this->baseUrl = \rtrim($baseUrl, '/');
        $timer = new SystemTimer();
        $this->sender = new Sender($transport, $timeoutMs ?? 8000, $maxRetries, $timer);
        $this->autocompleteSender = new Sender(
            $transport,
            $timeoutMs ?? self::AUTOCOMPLETE_TIMEOUT_MS,
            $maxRetries,
            $timer,
        );
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
     * @param int             $level The lookup level, from 1 to 5. PHP's own type check
     *                               refuses a value that is not a whole number.
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
        $response = $this->send($this->sender, '/v1/lookup', $query, retryTimeouts: true);

        return LookupResult::fromResponse($response, $postcode, $level);
    }

    /**
     * Finds the postcode at a point, such as a GPS fix. A point with no postcode within the
     * radius is a result with found false, not an error.
     *
     * ```php
     * $result = $client->reverse(9.0, 7.0);
     * echo $result->unit?->postcode->canonical; // FC-01-Z99-ZZ-01
     * echo $result->area; // FC-01-Z99-ZZ
     * ```
     *
     * @param float  $lat          The latitude in degrees, from -90 to 90.
     * @param float  $lng          The longitude in degrees, from -180 to 180.
     * @param ?float $maxDistanceM The radius in metres, from 0 to 250. With null, the gateway
     *                             applies its own default. The gateway cuts a larger radius to 250
     *                             without a sign, so the client refuses it.
     *
     * @throws PostcodeException For a number outside its range or not finite, and for each
     *                           failure of the gateway. The client sends no request for bad input.
     */
    public function reverse(float $lat, float $lng, ?float $maxDistanceM = null): ReverseResult
    {
        $query = [
            'lat' => self::decimal('The latitude', $lat, 90),
            'lng' => self::decimal('The longitude', $lng, 180),
        ];
        if ($maxDistanceM !== null) {
            $query['max_distance_m'] = self::decimal(
                'The radius',
                $maxDistanceM,
                self::MAX_RADIUS_M,
                0,
            );
        }
        $response = $this->send($this->sender, '/v1/search/reverse', $query, retryTimeouts: true);

        return ReverseResult::fromResponse($response);
    }

    /**
     * Suggests ways to complete the segment that the user types. The client normalises the text
     * with Postcode::normalize(). It sends no request for text that is empty, longer than a
     * postcode, or holds a character other than A to Z and 0 to 9. A timeout ends the call
     * without a retry, because the next keystroke replaces it.
     *
     * ```php
     * $result = $client->autocomplete('fc01z');
     * echo $result->segment->value; // district
     * echo $result->suggestions[0]->postcode?->canonical; // FC-01-Z99
     * ```
     *
     * @param string $q The text that the user typed.
     *
     * @throws PostcodeException For text that fails the check, and for each failure of the
     *                           gateway.
     */
    public function autocomplete(string $q): AutocompleteResult
    {
        // Like the other clients, refuse text over the input limit of the core before the
        // normalisation, which has no limit of its own. A string that is not UTF-8 fails here too.
        $typed = \preg_match(self::WITHIN_INPUT_LIMIT, $q) === 1 ? Postcode::normalize($q) : '';
        if (\preg_match(self::AUTOCOMPLETE_TEXT, $typed) !== 1) {
            throw PostcodeException::invalidInput(
                'The text must hold 1 to 11 letters A to Z and digits 0 to 9, after spaces and '
                    . 'hyphens are removed. Send no request until the user types one.',
            );
        }
        $response = $this->send(
            $this->autocompleteSender,
            '/v1/search/autocomplete',
            ['q' => $typed],
            retryTimeouts: false,
        );

        return AutocompleteResult::fromResponse($response, $typed);
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
     * The number in its shortest decimal form, after a check of its range.
     *
     * @throws PostcodeException For a number that is not finite or outside its range.
     */
    private static function decimal(string $name, float $number, int $max, ?int $min = null): string
    {
        $min ??= -$max;
        if (!\is_finite($number) || $number < $min || $number > $max) {
            throw PostcodeException::invalidInput(
                "{$name} must be a number from {$min} to {$max}.",
            );
        }

        return Decimal::shortest($number);
    }

    /**
     * @param array<string, string|int> $query
     *
     * @throws PostcodeException
     */
    private function send(Sender $sender, string $path, array $query, bool $retryTimeouts): mixed
    {
        $url = $this->baseUrl . $path . '?' . \http_build_query($query);
        $request = $this->requestFactory->createRequest('GET', $url);
        if ($this->apiKey !== null) {
            $request = $request->withHeader('X-API-Key', $this->apiKey);
        }

        return $sender->send($request, $retryTimeouts);
    }
}
