<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\ParseErrorCode;
use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InputLimitTest extends TestCase
{
    /**
     * Byte strings that are not valid UTF-8, each 256 bytes or shorter.
     *
     * @return array<string, array{string}>
     */
    public static function invalidUtf8(): array
    {
        return [
            'a byte that UTF-8 never uses' => ["EK01A03FK0\xFF"],
            'a sequence cut short' => ["EK01A03FK0\xC3"],
            'an encoded surrogate' => ["\xED\xA0\x80EK01A03FK01"],
            'an overlong slash' => ["EK01\xC0\xAFA03FK01"],
            'a lone continuation byte' => ["\x80EK01A03FK01"],
        ];
    }

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
    #[DataProvider('invalidUtf8')]
    public function rejectsTextThatIsNotValidUtf8AsABadCharacter(string $input): void
    {
        self::assertSame(ParseErrorCode::BadCharacter, Postcode::parse($input)->error?->code);
    }

    #[Test]
    public function rejectsMoreThan256BytesOfInvalidUtf8AsOverTheInputLimit(): void
    {
        $result = Postcode::parse(\str_repeat("\xFF", 257));

        self::assertSame(ParseErrorCode::BadLength, $result->error?->code);
    }

    #[Test]
    public function findsNoLegacyPostcodeInTextThatIsNotValidUtf8(): void
    {
        self::assertFalse(Postcode::isLegacy("900108\xFF"));
    }

    #[Test]
    public function keepsTheBytesThatAreNotValidUtf8WhenItNormalises(): void
    {
        self::assertSame("EK\xFF01", Postcode::normalize("e-k\xFF 01"));
    }
}
