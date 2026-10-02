<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\ParseErrorCode;
use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The input limit counts code points, and parse applies it before it normalises the input.
 * InvalidUtf8Test holds the rule for text that has no code points to count.
 */
final class InputLimitTest extends TestCase
{
    #[Test]
    public function countsAFourByteCharacterAsOneCodePoint(): void
    {
        // U+1F600 takes 4 bytes in UTF-8, and no NFKC form or separator changes it.
        $sixtyFour = \str_repeat("\u{1F600}", 64);
        $sixtyFive = $sixtyFour . "\u{1F600}";

        self::assertSame(ParseErrorCode::BadCharacter, Postcode::parse($sixtyFour)->error?->code);
        self::assertSame(ParseErrorCode::BadLength, Postcode::parse($sixtyFive)->error?->code);
    }

    #[Test]
    public function countsEachCombiningMarkAsOneCodePoint(): void
    {
        $sixtyFour = 'E' . \str_repeat("\u{0301}", 63);
        $sixtyFive = $sixtyFour . "\u{0301}";

        self::assertSame(ParseErrorCode::BadCharacter, Postcode::parse($sixtyFour)->error?->code);
        self::assertSame(ParseErrorCode::BadLength, Postcode::parse($sixtyFive)->error?->code);
    }

    #[Test]
    public function rejectsTenMegabytesOfTextAsOverTheInputLimit(): void
    {
        $result = Postcode::parse(\str_repeat('EK01A03FK01 ', 900_000));

        self::assertSame(ParseErrorCode::BadLength, $result->error?->code);
    }

    #[Test]
    public function parsesACodeThatSpansLines(): void
    {
        // The limit check counts a line break as one code point. If its pattern lacked the s
        // flag, the dot would stop at a line feed, and this short input would count as too long.
        $result = Postcode::parse("EK-01\nA03\r\nFK-01\r");

        self::assertSame('EK01A03FK01', $result->value?->compact);
    }
}
