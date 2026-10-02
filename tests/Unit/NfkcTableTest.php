<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Internal\NfkcTable;
use Gatepost\Postcode\Internal\SpecData;
use Gatepost\Postcode\ParseErrorCode;
use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NfkcTableTest extends TestCase
{
    /**
     * Characters from several Unicode blocks, with the ASCII text that NFKC gives for each.
     *
     * @return array<string, array{string, string}>
     */
    public static function compatibilityForms(): array
    {
        return [
            'a full-width capital A' => ["\u{FF21}", 'A'],
            'a full-width digit 7' => ["\u{FF17}", '7'],
            'a mathematical bold capital E' => ["\u{1D404}", 'E'],
            'a mathematical bold digit 1' => ["\u{1D7CF}", '1'],
            'a circled digit 1' => ["\u{2460}", '1'],
            'a superscript 2' => ["\u{00B2}", '2'],
            'the ligature fi' => ["\u{FB01}", 'fi'],
            'a Roman numeral 12' => ["\u{216B}", 'XII'],
            'a horizontal ellipsis' => ["\u{2026}", '...'],
            'the Kelvin sign' => ["\u{212A}", 'K'],
        ];
    }

    #[Test]
    #[DataProvider('compatibilityForms')]
    public function mapsACharacterToItsNfkcForm(string $character, string $form): void
    {
        self::assertSame($form, NfkcTable::FORMS[$character] ?? null);
    }

    #[Test]
    public function mapsEachCharacterToAsciiTextOrSeparators(): void
    {
        $outside = [];
        foreach (NfkcTable::FORMS as $character => $form) {
            $oneCodePoint = \preg_match('/\A.\z/su', $character) === 1;
            $ascii = \preg_match('/\A[\x00-\x7F]*\z/', \strtr($form, SpecData::SEPARATORS)) === 1;
            if (!$oneCodePoint || !$ascii) {
                $outside[] = \bin2hex($character);
            }
        }

        self::assertSame([], $outside);
    }

    #[Test]
    public function changesMathematicalBoldLettersAndDigitsToAscii(): void
    {
        // Both characters lie above U+FFFF, so their keys take 4 bytes in UTF-8.
        self::assertSame('E1', Postcode::normalize("\u{1D404}\u{1D7CF}"));
    }

    #[Test]
    public function namesTheUnicodeDataInTheNotice(): void
    {
        $notice = \file_get_contents(\dirname(__DIR__, 2) . '/NOTICE');
        self::assertIsString($notice);

        self::assertStringContainsString('src/Internal/NfkcTable.php', $notice);
        self::assertStringContainsString(
            'Unicode Character Database ' . NfkcTable::UNICODE_VERSION,
            $notice,
        );
        self::assertStringContainsString('Unicode-3.0', $notice);
    }

    #[Test]
    public function keepsACharacterWhoseNfkcFormHasOtherLetters(): void
    {
        // NFKC changes the micro sign U+00B5 to the Greek letter mu U+03BC. Neither can be part
        // of a postcode, so the table leaves the micro sign out.
        self::assertSame("EK\u{00B5}", Postcode::normalize("ek\u{00B5}"));
    }

    #[Test]
    public function rejectsACharacterThatTheTableLeavesOutAsFullNfkcWould(): void
    {
        $result = Postcode::parse("EK01A03FK0\u{00B5}");

        self::assertSame(ParseErrorCode::BadCharacter, $result->error?->code);
    }
}
