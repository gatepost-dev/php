<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 transport that gives its answers in order and records each request. An answer is a
 * response, or the number of milliseconds after which the connection fails.
 */
final class ScriptedTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param list<ResponseInterface|float> $answers
     */
    public function __construct(
        private array $answers,
        private readonly ?FakeTimer $timer = null,
    ) {}

    /**
     * A response with a JSON body.
     *
     * @param array<string, string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): ResponseInterface
    {
        $headers['Content-Type'] = 'application/json';

        return new Response($status, $headers, \json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * A response with the gateway's error body.
     *
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, array $headers = []): ResponseInterface
    {
        $body = ['error' => ['code' => $code, 'message' => 'A synthetic error.']];

        return self::json($status, $body, $headers);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $answer = \array_shift($this->answers);
        if ($answer === null) {
            throw new LogicException('The test expected no more requests.');
        }
        if ($answer instanceof ResponseInterface) {
            return $answer;
        }
        $this->timer?->advance($answer);

        throw new ConnectException('The synthetic connection failed.', $request);
    }
}
