<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Internal\SpecData;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

/**
 * A PSR-18 transport that answers with the synthetic fixtures of spec/fixtures, as the mock
 * server does for the requests of the doc examples. It needs no network and no Node, so the unit
 * tests and the doc examples can use it.
 */
final class FixtureGateway implements ClientInterface
{
    public const UNIT = 'FC-01-Z99-ZZ-01';

    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * A client that sends each request to a new FixtureGateway.
     */
    public static function client(): PostcodeClient
    {
        return new PostcodeClient(
            new self(),
            new HttpFactory(),
            apiKey: 'nipost_test_mock_l3',
            baseUrl: 'https://gateway.invalid',
        );
    }

    /**
     * Reads one fixture, such as lookup/valid-level-1, as a response.
     */
    public static function fixture(string $name): ResponseInterface
    {
        $text = \file_get_contents(\dirname(__DIR__, 3) . "/spec/fixtures/{$name}.json");
        $fixture = \json_decode((string) $text, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($fixture) || !\is_int($fixture['status'] ?? null)) {
            throw new UnexpectedValueException("The fixture {$name} has no status.");
        }
        $body = \json_encode($fixture['body'], \JSON_THROW_ON_ERROR);

        return new Response($fixture['status'], ['Content-Type' => 'application/json'], $body);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        \parse_str($request->getUri()->getQuery(), $query);

        return match ($request->getUri()->getPath()) {
            '/v1/lookup' => self::lookup($query),
            '/v1/search/reverse' => self::reverse($query),
            '/v1/search/autocomplete' => self::autocomplete($query),
            default => self::fixture('errors/unknown-path'),
        };
    }

    /**
     * @param array<mixed> $query
     */
    private static function lookup(array $query): ResponseInterface
    {
        if (self::text($query, 'code') !== self::UNIT) {
            return self::fixture('lookup/not-found');
        }
        // The client of this gateway holds a level 3 key, as the mock key nipost_test_mock_l3 does.
        $level = self::text($query, 'level');
        if ((int) $level > 3) {
            return self::fixture('errors/level-not-granted-3');
        }

        return self::fixture("lookup/valid-level-{$level}");
    }

    /**
     * @param array<mixed> $query
     */
    private static function reverse(array $query): ResponseInterface
    {
        $point = [(float) self::text($query, 'lat'), (float) self::text($query, 'lng')];

        return match ($point) {
            [9.0, 7.0] => self::fixture('reverse/unit'),
            [9.001, 7.001] => self::fixture('reverse/area'),
            default => self::fixture('reverse/not-found'),
        };
    }

    /**
     * The segment of the last typed character, and the value of that segment in the synthetic
     * unit when the text is a prefix of it. The spec has no autocomplete fixture, so this builds
     * the answer, as the mock server does.
     *
     * @param array<mixed> $query
     */
    private static function autocomplete(array $query): ResponseInterface
    {
        $typed = self::text($query, 'q');
        $unit = \str_replace('-', '', self::UNIT);
        foreach (SpecData::SEGMENTS as $name => $segment) {
            if (\strlen($typed) <= $segment['end']) {
                $value = \substr($unit, $segment['start'], $segment['length']);
                $suggestions = \str_starts_with($unit, $typed) ? [['code' => $value]] : [];

                return ScriptedTransport::json(200, [
                    'data' => ['segment' => $name, 'suggestions' => $suggestions],
                ]);
            }
        }

        return self::fixture('errors/invalid-request');
    }

    /**
     * @param array<mixed> $query
     */
    private static function text(array $query, string $name): string
    {
        $value = $query[$name] ?? '';

        return \is_string($value) ? $value : '';
    }
}
