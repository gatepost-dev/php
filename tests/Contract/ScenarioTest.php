<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Contract;

use Gatepost\Postcode\Client\AutocompleteResult;
use Gatepost\Postcode\Client\LookupResult;
use Gatepost\Postcode\Client\PostcodeClient;
use Gatepost\Postcode\Client\PostcodeException;
use Gatepost\Postcode\Client\ReverseResult;
use Gatepost\Postcode\Tests\Unit\Client\ArrayCache;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use UnexpectedValueException;

/**
 * Runs each contract scenario of the spec against the mock server. The scenarios use the real
 * clock, because they measure the waits of the client. The client is synchronous, so the suite
 * leaves out the scenarios whose calls run in parallel.
 */
final class ScenarioTest extends MockServerTestCase
{
    // spec/contract/README.md lets a timer fire this much later than the upper bound of a wait.
    private const LATE_TIMER_MS = 100;

    /**
     * @return array<string, array{Scenario}>
     */
    public static function scenarios(): array
    {
        return \array_map(
            static fn(Scenario $scenario): array => [$scenario],
            Scenario::runnable(),
        );
    }

    #[Test]
    #[DataProvider('scenarios')]
    public function reachesTheOutcomeOfTheScenario(Scenario $scenario): void
    {
        $option = $scenario->client['timeoutMs'] ?? null;
        $timeoutMs = $option === null ? null : (int) $option;
        $transport = new RecordingTransport(
            new Client(['timeout' => ($timeoutMs ?? self::defaultTimeoutMs($scenario)) / 1000]),
            $scenario->id,
            \bin2hex(\random_bytes(8)),
        );
        $client = $this->client($scenario, $transport, $timeoutMs);

        $outcomes = \array_map(
            static fn(array $call): array => self::outcome($client, $call),
            $scenario->calls,
        );

        self::assertSame($scenario->outcomes(), $outcomes);
        self::assertCount($scenario->attempts(), $transport->requests, 'The number of attempts');
        self::assertWaits($scenario->waits(), $transport->waitsMs());
        self::assertFirstRequest($scenario, $transport);
        self::assertSame(
            $scenario->expect['maxInFlight'] ?? $transport->maxInFlight,
            $transport->maxInFlight,
            'The requests in flight',
        );
    }

    private function client(
        Scenario $scenario,
        RecordingTransport $transport,
        ?int $timeoutMs,
    ): PostcodeClient {
        $options = $scenario->client;
        $apiKey = $options['apiKey'] ?? null;
        $cacheTtlMs = (int) ($options['cacheTtlMs'] ?? 0);

        return new PostcodeClient(
            $transport,
            new HttpFactory(),
            apiKey: \is_string($apiKey) ? $apiKey : null,
            baseUrl: $this->mockUrl,
            timeoutMs: $timeoutMs,
            maxRetries: (int) ($options['maxRetries'] ?? 2),
            cacheTtlMs: $cacheTtlMs,
            cache: $cacheTtlMs > 0 ? new ArrayCache() : null,
        );
    }

    // Without the option, the client waits 15 s for autocomplete and 8 s for the other calls.
    private static function defaultTimeoutMs(Scenario $scenario): int
    {
        foreach ($scenario->calls as $call) {
            if ($call['method'] !== 'autocomplete') {
                return 8000;
            }
        }

        return 15000;
    }

    /**
     * @param array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    private static function outcome(PostcodeClient $client, array $call): array
    {
        try {
            return ['result' => Outcome::of(self::make($client, $call))];
        } catch (PostcodeException $error) {
            return ['error' => Outcome::error($error)];
        }
    }

    /**
     * @param array<string, mixed> $call
     */
    private static function make(
        PostcodeClient $client,
        array $call,
    ): LookupResult|ReverseResult|AutocompleteResult {
        $maxDistanceM = $call['maxDistanceM'] ?? null;

        return match ($call['method']) {
            'lookup' => $client->lookup(self::text($call, 'code'), self::level($call)),
            'reverse' => $client->reverse(
                self::number($call, 'lat'),
                self::number($call, 'lng'),
                $maxDistanceM === null ? null : self::number($call, 'maxDistanceM'),
            ),
            'autocomplete' => $client->autocomplete(self::text($call, 'q')),
            default => throw new UnexpectedValueException('The call names no known method.'),
        };
    }

    /**
     * @param list<array{min: int, max: int}> $bounds
     * @param list<float>                     $waitsMs
     */
    private static function assertWaits(array $bounds, array $waitsMs): void
    {
        if ($bounds === []) {
            return;
        }
        self::assertCount(\count($bounds), $waitsMs, 'The number of waits');
        foreach ($bounds as $index => $bound) {
            $wait = \sprintf('Wait %d took %.1f ms.', $index + 1, $waitsMs[$index]);
            self::assertGreaterThanOrEqual($bound['min'], $waitsMs[$index], $wait);
            $latest = $bound['max'] + self::LATE_TIMER_MS;
            self::assertLessThanOrEqual($latest, $waitsMs[$index], $wait);
        }
    }

    private static function assertFirstRequest(
        Scenario $scenario,
        RecordingTransport $transport,
    ): void {
        $expected = $scenario->expect['request'] ?? null;
        if (!\is_array($expected)) {
            return;
        }
        $request = $transport->requests[0];
        \parse_str($request->getUri()->getQuery(), $query);
        $apiKey = $request->hasHeader('X-API-Key') ? $request->getHeaderLine('X-API-Key') : null;

        self::assertSame($expected, [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $query,
            'apiKey' => $apiKey,
        ]);
    }

    /**
     * @param array<string, mixed> $call
     */
    private static function text(array $call, string $name): string
    {
        $value = $call[$name] ?? null;

        return \is_string($value) ? $value : throw new UnexpectedValueException("No {$name}.");
    }

    /**
     * @param array<string, mixed> $call
     */
    private static function level(array $call): int
    {
        $level = $call['level'] ?? 1;

        return \is_int($level) ? $level : throw new UnexpectedValueException('No level.');
    }

    /**
     * @param array<string, mixed> $call
     */
    private static function number(array $call, string $name): int|float
    {
        $value = $call[$name] ?? null;

        return \is_int($value) || \is_float($value)
            ? $value
            : throw new UnexpectedValueException("No {$name}.");
    }
}
