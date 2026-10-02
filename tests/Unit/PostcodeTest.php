<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostcodeTest extends TestCase
{
    #[Test]
    public function claimsTheVersionOfTheSpecSubmodule(): void
    {
        $specVersion = \file_get_contents(\dirname(__DIR__, 2) . '/spec/VERSION');
        self::assertIsString($specVersion);

        self::assertSame(\trim($specVersion), Postcode::SPEC_VERSION);
    }
}
