<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * A test that calls the mock server. Start the mock server from a checkout of the js repo, with
 * GATEPOST_SPEC_DIR set to this repo's spec folder, and set GATEPOST_MOCK_URL to its address. A
 * test without the address fails, so a CI job without the mock server cannot pass.
 */
abstract class MockServerTestCase extends TestCase
{
    protected string $mockUrl;

    protected function setUp(): void
    {
        $mockUrl = \getenv('GATEPOST_MOCK_URL');
        if ($mockUrl === false || $mockUrl === '') {
            self::fail(
                'Set GATEPOST_MOCK_URL to the address of a running mock server. The README '
                    . 'says how to start one.',
            );
        }
        $this->mockUrl = $mockUrl;
    }
}
