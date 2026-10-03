<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

use RuntimeException;
use Throwable;

/**
 * The error of a client call: bad input, a refusal or failure of the gateway, or no response.
 * errorCode() gives the reason as a stable code. No message holds the API key.
 */
final class PostcodeException extends RuntimeException
{
    private ?int $status = null;

    private ?string $apiCode = null;

    private ?int $retryAfterMs = null;

    private function __construct(
        string $message,
        private readonly ErrorCode $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * @internal The client checks its input before it sends a request.
     */
    public static function invalidInput(string $message): self
    {
        return new self($message, ErrorCode::InvalidInput);
    }

    /**
     * @internal The client maps each status that is not 200 with spec/client.md.
     *
     * @param ?string $apiCode      The error.code of the response body, or null.
     * @param ?int    $retryAfterMs The wait that Retry-After asked for, or null.
     */
    public static function fromStatus(int $status, ?string $apiCode, ?int $retryAfterMs): self
    {
        $errorCode = self::errorCodeFor($status, $apiCode);
        $error = new self(self::messageFor($errorCode, $status, $retryAfterMs), $errorCode);
        $error->status = $status;
        $error->apiCode = $apiCode;
        $error->retryAfterMs = $retryAfterMs;

        return $error;
    }

    /**
     * @internal A response with status 200 must hold a JSON object with a data field, and the
     *           parts that the contract uses.
     */
    public static function unreadable(int $status): self
    {
        $message = "The gateway sent a response that the client cannot read (HTTP {$status}). "
            . 'Try again later, and report it if it happens again.';

        $error = new self($message, ErrorCode::UnexpectedResponse);
        $error->status = $status;

        return $error;
    }

    /**
     * @internal The transport failed before any response arrived.
     */
    public static function networkError(Throwable $previous): self
    {
        $message = 'The client could not reach the gateway. Check the network and the base URL.';

        return new self($message, ErrorCode::NetworkError, $previous);
    }

    /**
     * @internal The transport failed after the attempt reached the timeout.
     */
    public static function timeout(int $timeoutMs, Throwable $previous): self
    {
        $message = "The gateway sent no response within {$timeoutMs} ms. Try again later.";

        return new self($message, ErrorCode::Timeout, $previous);
    }

    /**
     * The reason for the failure, as a stable code.
     */
    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * The HTTP status of the response, or null when no response arrived.
     */
    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * The error.code of the response body, such as level_not_granted, or null.
     */
    public function apiCode(): ?string
    {
        return $this->apiCode;
    }

    /**
     * The wait in milliseconds that the Retry-After header of a 429 asked for, or null. Tell
     * the user when to try again.
     */
    public function retryAfterMs(): ?int
    {
        return $this->retryAfterMs;
    }

    private static function errorCodeFor(int $status, ?string $apiCode): ErrorCode
    {
        return match (true) {
            $status === 401 => ErrorCode::Unauthorized,
            $status === 402 => ErrorCode::InsufficientCredits,
            $status === 403 && $apiCode === 'origin_not_allowed' => ErrorCode::OriginNotAllowed,
            $status === 403 => ErrorCode::Forbidden,
            $status === 429 => ErrorCode::RateLimited,
            $status >= 400 && $status <= 499 => ErrorCode::InvalidInput,
            default => ErrorCode::ServerError,
        };
    }

    private static function messageFor(
        ErrorCode $errorCode,
        int $status,
        ?int $retryAfterMs,
    ): string {
        $wait = $retryAfterMs === null ? 'Wait' : 'Wait ' . \ceil($retryAfterMs / 1000) . ' s';

        return match ($errorCode) {
            ErrorCode::Unauthorized => "The gateway refused the API key (HTTP {$status}). "
                . 'Check the key that the client sends.',
            ErrorCode::InsufficientCredits => "The account has no credits for this lookup level "
                . "(HTTP {$status}). Add credits, or ask for a lower level.",
            ErrorCode::OriginNotAllowed => "The key does not allow the origin of this request "
                . "(HTTP {$status}). Add the origin to the key in the NIPOST dashboard.",
            ErrorCode::Forbidden => "The key does not allow this request (HTTP {$status}). "
                . 'Check the scope and the lookup level of the key.',
            ErrorCode::RateLimited => "The gateway refused the request because the key sent too "
                . "many (HTTP {$status}). {$wait}, then try again.",
            ErrorCode::InvalidInput => "The gateway rejected the request (HTTP {$status}). "
                . 'Check the input of the call.',
            default => "The gateway failed (HTTP {$status}). Try again later.",
        };
    }
}
