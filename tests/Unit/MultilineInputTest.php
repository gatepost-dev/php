<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MultilineInputTest extends TestCase
{
    #[Test]
    public function parsesACodeThatSpansLines(): void
    {
        // The limit check counts a line break as one code point. If its pattern lacked the s
        // flag, the dot would stop at a line feed, and this short input would count as too long.
        $result = Postcode::parse("EK-01\nA03\r\nFK-01\r");

        self::assertSame('EK01A03FK01', $result->value?->compact);
    }
}
