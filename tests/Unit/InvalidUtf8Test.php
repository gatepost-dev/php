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

/**
 * Text that is not valid UTF-8 has no code points to count. parse gives it BadCharacter, or
 * BadLength when it has more than 4 bytes for each code point of the input limit.
 */
final class InvalidUtf8Test extends TestCase
{
    /**
     * Short byte strings that are not valid UTF-8. Each one breaks UTF-8 in a different way.
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
    public function rejectsInvalidUtf8UpTo256BytesAsABadCharacter(): void
    {
        // Both strings have more bytes than the input limit has code points. The limit for
        // invalid text is 4 bytes for each of those code points, so 256 bytes.
        $aboveTheCodePointLimit = Postcode::parse(\str_repeat('A', 70) . "\xFF");
        $atTheByteLimit = Postcode::parse(\str_repeat('A', 255) . "\xFF");

        self::assertSame(ParseErrorCode::BadCharacter, $aboveTheCodePointLimit->error?->code);
        self::assertSame(ParseErrorCode::BadCharacter, $atTheByteLimit->error?->code);
    }

    #[Test]
    public function rejectsInvalidUtf8WithOneByteOver256AsOverTheInputLimit(): void
    {
        $result = Postcode::parse(\str_repeat('A', 256) . "\xFF");

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
