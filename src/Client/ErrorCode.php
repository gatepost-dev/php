<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Client;

/**
 * Why a client call failed. spec/client.md maps each HTTP status to one of these codes. Each
 * value is the wire value that every Gatepost SDK uses.
 */
enum ErrorCode: string
{
    /** The client's own check of the input failed, or the gateway rejected the request. */
    case InvalidInput = 'invalid_input';
    /** The gateway refused the API key, or the client sent none (HTTP 401). */
    case Unauthorized = 'unauthorized';
    /** The account has no credits for the lookup level (HTTP 402). */
    case InsufficientCredits = 'insufficient_credits';
    /** The gateway refused the origin of the request for a publishable key (HTTP 403). */
    case OriginNotAllowed = 'origin_not_allowed';
    /** The key lacks the scope or the lookup level for the request (HTTP 403). */
    case Forbidden = 'forbidden';
    /** The gateway refused the request because the key sent too many (HTTP 429). */
    case RateLimited = 'rate_limited';
    /** The gateway failed (HTTP 502, 503, 504 or another status that is not 200). */
    case ServerError = 'server_error';
    /** The gateway sent status 200, but the body lacks a part that the client needs. */
    case UnexpectedResponse = 'unexpected_response';
    /** No response arrived, because the connection failed. */
    case NetworkError = 'network_error';
    /** No response arrived within the timeout. */
    case Timeout = 'timeout';
}
