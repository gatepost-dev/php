<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use Closure;
use Gatepost\Postcode\Client\Internal\Decimal;
use Gatepost\Postcode\Client\Internal\ResultCache;
use Gatepost\Postcode\Client\Internal\Sender;
use Gatepost\Postcode\Client\Internal\SystemTimer;
use Gatepost\Postcode\Client\Internal\Timer;
use Gatepost\Postcode\Internal\SpecData;
use Gatepost\Postcode\Postcode;
use Gatepost\Postcode\Precision;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Calls NIPOST's postcode gateway with your own API key. Each call blocks until it has a result
 * or a PostcodeException. A call that fails for a reason that can pass, such as a 503, is tried
 * again, up to maxRetries times. The client keeps no result unless cacheTtlMs is above 0.
 *
 * Unofficial. Not made or endorsed by NIPOST.
 *
 * ```php
 * $client = new PostcodeClient(
 *     new \GuzzleHttp\Client(['timeout' => 15]),
 *     new \GuzzleHttp\Psr7\HttpFactory(),
 *     apiKey: \getenv('NIPOST_API_KEY') ?: null,
 *     timeoutMs: 15000,
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

    private readonly ?ResultCache $cache;

    /**
     * @param ClientInterface         $transport      A PSR-18 HTTP client. PSR-18 has no timeout,
     *                                                so set the timeout of the transport and pass
     *                                                the same value as timeoutMs. 15 s covers
     *                                                autocomplete. The client reads a failed
     *                                                attempt as a timeout when it took timeoutMs
     *                                                or more, and as a network_error when it
     *                                                took less.
     * @param RequestFactoryInterface $requestFactory A PSR-17 factory for the requests.
     * @param ?string                 $apiKey         The key for the X-API-Key header. With null,
     *                                                the client sends no key, and the gateway
     *                                                answers 401.
     * @param string                  $baseUrl        The gateway's address.
     * @param ?int                    $timeoutMs      The longest wait for one attempt, for
     *                                                every call. A failed attempt that took this
     *                                                long is a timeout. With null, it is 8000,
     *                                                and 15000 for autocomplete. Pass the timeout
     *                                                of the transport.
     * @param int                     $maxRetries     The most retries after the first attempt.
     * @param int                     $cacheTtlMs     How long the client keeps a result. 0 turns
     *                                                the cache off.
     * @param ?CacheInterface         $cache          A PSR-16 cache for the results. The client
     *                                                needs one when cacheTtlMs is above 0.
     * @param ?Timer                  $timer          The clock and the waits of the client.
     *                                                Only the tests pass it. Leave it null.
     *
     * @throws InvalidArgumentException When an option is out of range, the key is empty, or
     *                                  cacheTtlMs is above 0 with no cache.
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
        int $cacheTtlMs = 0,
        ?CacheInterface $cache = null,
        ?Timer $timer = null,
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
        if ($cacheTtlMs < 0) {
            throw new InvalidArgumentException("cacheTtlMs is {$cacheTtlMs}. Use 0 or more.");
        }
        if ($cacheTtlMs > 0 && $cache === null) {
            throw new InvalidArgumentException('cacheTtlMs is above 0. Pass a PSR-16 cache too.');
        }
        $this->baseUrl = \rtrim($baseUrl, '/');
        $timer ??= new SystemTimer();
        $this->sender = new Sender($transport, $timeoutMs ?? 8000, $maxRetries, $timer);
        $this->autocompleteSender = new Sender(
            $transport,
            $timeoutMs ?? self::AUTOCOMPLETE_TIMEOUT_MS,
            $maxRetries,
            $timer,
        );
        $this->cache = $cache !== null && $cacheTtlMs > 0
            ? new ResultCache($cache, $cacheTtlMs, $timer)
            : null;
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
        $read = static fn(mixed $response): LookupResult => LookupResult::fromResponse(
            $response,
            $postcode,
            $level,
        );

        return $this->call(
            $this->sender,
            $this->url('/v1/lookup', $query),
            $read,
            retryTimeouts: true,
        );
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
        $read = ReverseResult::fromResponse(...);

        return $this->call(
            $this->sender,
            $this->url('/v1/search/reverse', $query),
            $read,
            retryTimeouts: true,
        );
    }

    /**
     * Suggests ways to complete the segment that the user types. The client normalises the text
     * with Postcode::normalize(). It sends no request for text that is empty, longer than a
     * postcode, or holds a character other than A to Z and 0 to 9. A timeout ends the call
     * without a retry, because the next keystroke replaces it. The call waits 15 s by default,
     * so set the timeout of the transport to 15 s and pass the same timeoutMs. With a
     * shorter transport timeout, the failure reads as a network_error, and the client retries
     * it.
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
        // normalisation, which has no limit of its own. preg_match gives false for a string
        // that is not UTF-8.
        $within = \preg_match(self::WITHIN_INPUT_LIMIT, $q);
        if ($within === false) {
            throw PostcodeException::invalidInput('The text is not valid UTF-8.');
        }
        if ($within === 0) {
            throw PostcodeException::invalidInput(
                'The text has more than ' . SpecData::MAX_INPUT_CODE_POINTS . ' characters. '
                    . 'Send no request for it.',
            );
        }
        $typed = Postcode::normalize($q);
        if (\preg_match(self::AUTOCOMPLETE_TEXT, $typed) !== 1) {
            throw PostcodeException::invalidInput(
                'The text must hold 1 to 11 letters A to Z and digits 0 to 9, after spaces and '
                    . 'hyphens are removed. Send no request until the user types one.',
            );
        }
        $read = static fn(mixed $response): AutocompleteResult => AutocompleteResult::fromResponse(
            $response,
            $typed,
        );

        return $this->call(
            $this->autocompleteSender,
            $this->url('/v1/search/autocomplete', ['q' => $typed]),
            $read,
            retryTimeouts: false,
        );
    }

    /**
     * Removes every result that any client of the same cache kept, also the results of a client
     * with another API key. The caller's other cache entries stay. A request that started before
     * this call keeps no result. A failure of the cache passes to the caller.
     *
     * ```php
     * $client->clearCache();
     * ```
     */
    public function clearCache(): void
    {
        $this->cache?->clear();
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
     */
    private function url(string $path, array $query): string
    {
        // An explicit separator: a host can set arg_separator.output to &amp;, and the gateway
        // then reads no second parameter.
        $text = \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        return $this->baseUrl . $path . '?' . $text;
    }

    /**
     * Gives the kept result of the call, or sends it, reads the response and keeps the data.
     * The client keeps no error, and it keeps data only after it read the data without one.
     *
     * @template T
     *
     * @param Closure(mixed): T $read
     *
     * @return T
     *
     * @throws PostcodeException
     */
    private function call(
        Sender $sender,
        string $url,
        Closure $read,
        bool $retryTimeouts,
    ): mixed {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/json');
        if ($this->apiKey !== null) {
            $request = $request->withHeader('X-API-Key', $this->apiKey);
        }
        $cache = $this->cache;
        if ($cache === null) {
            return $read($sender->send($request, $retryTimeouts));
        }
        // The URL holds the canonical postcode and the level. A cache can serve two clients, so
        // each API key keeps its own results.
        $slot = $cache->slot($url, $this->apiKey ?? '');
        if ($slot === null) {
            return $read($sender->send($request, $retryTimeouts));
        }
        $kept = $cache->get($slot);
        if ($kept !== null) {
            try {
                return $read($kept);
            } catch (PostcodeException) {
                // An entry that this client cannot read is a miss. The new result replaces it.
            }
        }
        $response = $sender->send($request, $retryTimeouts);
        $result = $read($response);
        $cache->put($slot, $response);

        return $result;
    }
}
