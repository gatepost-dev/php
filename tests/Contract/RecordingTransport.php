<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Contract;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The transport wrapper of spec/contract/README.md. It adds the two scenario headers to each
 * request, which the client itself never sends, and it records each attempt.
 */
final class RecordingTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<float> The start of each attempt, in milliseconds. */
    public array $startsMs = [];

    /** @var list<float> The end of each attempt, in milliseconds. */
    public array $endsMs = [];

    public int $maxInFlight = 0;

    private int $inFlight = 0;

    public function __construct(
        private readonly ClientInterface $transport,
        private readonly string $scenarioId,
        private readonly string $run,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $marked = $request
            ->withHeader('X-Scenario-Id', $this->scenarioId)
            ->withHeader('X-Scenario-Run', $this->run);
        ++$this->inFlight;
        $this->maxInFlight = \max($this->maxInFlight, $this->inFlight);
        $this->startsMs[] = \microtime(true) * 1000;
        try {
            return $this->transport->sendRequest($marked);
        } finally {
            $this->endsMs[] = \microtime(true) * 1000;
            --$this->inFlight;
        }
    }

    /**
     * Each wait, from the end of one attempt to the start of the next one.
     *
     * @return list<float>
     */
    public function waitsMs(): array
    {
        $waits = [];
        foreach (\array_slice($this->startsMs, 1) as $index => $startMs) {
            $waits[] = $startMs - $this->endsMs[$index];
        }

        return $waits;
    }
}
