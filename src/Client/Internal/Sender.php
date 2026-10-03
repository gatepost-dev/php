<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Gatepost\Postcode\Client\ErrorCode;
use Gatepost\Postcode\Client\PostcodeException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends one call as one or more attempts, with the retries and waits of spec/client.md. A PSR-18
 * transport has no timeout of its own, so an attempt that fails after timeoutMs or more counts as
 * a timeout. The caller sets the same timeout on the transport.
 *
 * @internal
 */
final class Sender
{
    private const FIRST_WAIT_MS = 500;
    private const MAX_JITTER_MS = 250;
    private const LONGEST_RETRY_AFTER_MS = 10_000;
    private const RETRIED_STATUSES = [502, 503, 504];
    private const HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    public function __construct(
        private readonly ClientInterface $transport,
        private readonly int $timeoutMs,
        private readonly int $maxRetries,
        private readonly Timer $timer,
    ) {}

    /**
     * Sends the request until an attempt succeeds, an error is final or the retries run out.
     *
     * @param bool $retryTimeouts False for autocomplete, whose next keystroke replaces the call.
     *
     * @return mixed The data field of the response body.
     *
     * @throws PostcodeException
     */
    public function send(RequestInterface $request, bool $retryTimeouts): mixed
    {
        $deadlineMs = $this->timer->nowMs() + $this->budgetMs();
        for ($retry = 1; ; ++$retry) {
            try {
                return $this->attempt($request);
            } catch (PostcodeException $error) {
                $waitMs = $this->waitMs($retry, $error, $retryTimeouts);
                if ($waitMs === null || $this->timer->nowMs() + $waitMs > $deadlineMs) {
                    throw $error;
                }
                $this->timer->wait($waitMs);
            }
        }
    }

    /**
     * The total time of a call: every attempt takes the timeout, and every retry waits the
     * longest backoff.
     */
    private function budgetMs(): float
    {
        $budgetMs = (1 + $this->maxRetries) * $this->timeoutMs;
        for ($retry = 1; $retry <= $this->maxRetries; ++$retry) {
            $budgetMs += self::backoffBaseMs($retry) + self::MAX_JITTER_MS;
        }

        return $budgetMs;
    }

    private function attempt(RequestInterface $request): mixed
    {
        $startedMs = $this->timer->nowMs();
        try {
            $response = $this->transport->sendRequest($request);
        } catch (ClientExceptionInterface $failure) {
            $tookMs = $this->timer->nowMs() - $startedMs;
            throw $tookMs >= $this->timeoutMs
                ? PostcodeException::timeout($this->timeoutMs, $failure)
                : PostcodeException::networkError($failure);
        }

        return self::dataField($response, $this->retryAfterMs($response));
    }

    /**
     * The wait before a retry, or null when the error is final.
     */
    private function waitMs(int $retry, PostcodeException $error, bool $retryTimeouts): ?int
    {
        if ($retry > $this->maxRetries) {
            return null;
        }

        return match ($error->errorCode()) {
            ErrorCode::NetworkError => $this->backoffMs($retry),
            ErrorCode::Timeout => $retryTimeouts ? $this->backoffMs($retry) : null,
            ErrorCode::ServerError => \in_array($error->status(), self::RETRIED_STATUSES, true)
                ? self::shortRetryAfterMs($error) ?? $this->backoffMs($retry)
                : null,
            // With no Retry-After, or a long one, a retry in the same clock minute would fail too.
            ErrorCode::RateLimited => self::shortRetryAfterMs($error),
            default => null,
        };
    }

    /**
     * The wait that Retry-After asked for, or null when there is none or it is too long.
     */
    private static function shortRetryAfterMs(PostcodeException $error): ?int
    {
        $retryAfterMs = $error->retryAfterMs();

        return $retryAfterMs !== null && $retryAfterMs <= self::LONGEST_RETRY_AFTER_MS
            ? $retryAfterMs
            : null;
    }

    /**
     * The first retry waits 500 to 750 ms, and each later one waits twice as long.
     */
    private function backoffMs(int $retry): int
    {
        return self::backoffBaseMs($retry) + $this->timer->jitterMs(self::MAX_JITTER_MS);
    }

    private static function backoffBaseMs(int $retry): int
    {
        return self::FIRST_WAIT_MS * 2 ** ($retry - 1);
    }

    /**
     * @throws PostcodeException
     */
    private static function dataField(ResponseInterface $response, ?int $retryAfterMs): mixed
    {
        $status = $response->getStatusCode();
        $body = \json_decode((string) $response->getBody(), true);
        if ($status !== 200) {
            throw PostcodeException::fromStatus($status, self::apiCode($body), $retryAfterMs);
        }
        if (!\is_array($body) || !\array_key_exists('data', $body)) {
            throw PostcodeException::unreadable($status);
        }

        return $body['data'];
    }

    /**
     * The error.code of an error body. The client never fails on an unexpected error body.
     */
    private static function apiCode(mixed $body): ?string
    {
        $error = \is_array($body) ? ($body['error'] ?? null) : null;
        $code = \is_array($error) ? ($error['code'] ?? null) : null;

        return \is_string($code) ? $code : null;
    }

    /**
     * The wait that the Retry-After header of a 429, 502, 503 or 504 asks for, as seconds or as
     * an HTTP date. A header in another form counts as no header. A date in the past is valid
     * for a 429 only.
     */
    private function retryAfterMs(ResponseInterface $response): ?int
    {
        $status = $response->getStatusCode();
        $value = \trim($response->getHeaderLine('Retry-After'));
        $mayWait = $status === 429 || \in_array($status, self::RETRIED_STATUSES, true);
        if ($value === '' || !$mayWait) {
            return null;
        }
        if (\preg_match('/\A\d{1,9}\z/', $value) === 1) {
            return (int) $value * 1000;
        }
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, $utc);
        if ($date === false) {
            return null;
        }
        $waitMs = (int) \ceil($date->getTimestamp() * 1000 - $this->timer->nowMs());

        return $waitMs < 0 && $status !== 429 ? null : \max(0, $waitMs);
    }
}
