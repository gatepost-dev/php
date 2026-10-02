<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Internal\NfkcTable;
use Gatepost\Postcode\Internal\SpecData;
use Gatepost\Postcode\Postcode;
use Gatepost\Postcode\Precision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Checks rules that must hold for every input, on many generated inputs. PHP has no standard
 * property-testing tool, so a seeded Mersenne Twister makes the inputs. The fixed seed gives the
 * same inputs in each run, so a failure always repeats.
 */
final class PropertiesTest extends TestCase
{
    private const SEED = 20261002;
    private const RUNS = 500;
    private const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const DIGITS = '0123456789';
    private const ALPHABETS = [
        'letters' => self::LETTERS,
        'digits' => self::DIGITS,
        'letters-or-digits' => self::LETTERS . self::DIGITS,
    ];
    private const LOOK_ALIKES = ['O', 'I', 'L', '0', '1'];

    protected function setUp(): void
    {
        \mt_srand(self::SEED);
    }

    #[Test]
    public function parsesEveryValidCodeAndKeepsItsFormsInStep(): void
    {
        for ($run = 0; $run < self::RUNS; ++$run) {
            $compact = self::validCode();
            $postcode = Postcode::parse($compact)->value;

            self::assertNotNull($postcode, $compact);
            self::assertSame($compact, $postcode->compact);
            self::assertSame($compact, \str_replace('-', '', $postcode->canonical));
            self::assertSame($compact, \str_replace(' ', '', $postcode->display));
        }
    }

    #[Test]
    public function readsTheSameCodeWhenSeparatorsSitBetweenCharacters(): void
    {
        $separators = \array_keys(SpecData::SEPARATORS);
        for ($run = 0; $run < self::RUNS; ++$run) {
            $compact = self::validCode();
            $spaced = '';
            foreach (\str_split($compact) as $character) {
                $spaced .= $character . self::pick($separators);
            }

            $parsed = Postcode::parse($spaced)->value?->compact;

            self::assertSame($compact, $parsed, \bin2hex($spaced));
        }
    }

    #[Test]
    public function returnsAPostcodeOrAnErrorForAnyText(): void
    {
        $pieces = [
            ...\str_split(self::LETTERS . self::DIGITS),
            ...\array_keys(SpecData::SEPARATORS),
            ...\array_keys(NfkcTable::FORMS),
            "\u{0301}", "\u{00B5}", "\u{202E}", "\u{1F600}", "\xFF", "\xC3", "\xED\xA0\x80",
        ];
        for ($run = 0; $run < self::RUNS; ++$run) {
            $text = '';
            for ($count = \mt_rand(0, 80); $count > 0; --$count) {
                $text .= self::pick($pieces);
            }
            $result = Postcode::parse($text, allowPartial: \mt_rand(0, 1) === 1);

            self::assertNotSame($result->value === null, $result->error === null, \bin2hex($text));
        }
    }

    #[Test]
    public function suggestsOnlyCodesThatParse(): void
    {
        $suggestions = [];
        for ($run = 0; $run < self::RUNS; ++$run) {
            $compact = self::validCode();
            $place = \mt_rand(0, \strlen($compact) - 1);
            $typo = \substr_replace($compact, self::pick(self::LOOK_ALIKES), $place, 1);
            $suggestion = Postcode::parse($typo)->error?->suggestion;
            if ($suggestion !== null) {
                $suggestions[$typo] = $suggestion;
            }
        }

        self::assertNotSame([], $suggestions);
        foreach ($suggestions as $typo => $suggestion) {
            self::assertTrue(Postcode::parse($suggestion)->isOk(), "{$typo} gave {$suggestion}");
        }
    }

    #[Test]
    public function normalisesAsciiTextCompletelyInOnePass(): void
    {
        for ($run = 0; $run < self::RUNS; ++$run) {
            $text = '';
            for ($count = \mt_rand(0, 40); $count > 0; --$count) {
                $text .= \chr(\mt_rand(0, 0x7F));
            }
            $once = Postcode::normalize($text);

            self::assertSame($once, Postcode::normalize($once), \bin2hex($text));
        }
    }

    // The state comes from the state list, because most pairs of letters are not a state. Each
    // other segment follows its length, characters and minimum in the spec data.
    private static function validCode(): string
    {
        $code = '';
        foreach (SpecData::SEGMENTS as $name => $rule) {
            $code .= $name === Precision::State->value
                ? self::pick(\array_keys(SpecData::STATES))
                : self::segment($rule);
        }

        return $code;
    }

    /**
     * @param array{length: int, characters: key-of<self::ALPHABETS>, minimum: ?int} $rule
     */
    private static function segment(array $rule): string
    {
        $alphabet = \str_split(self::ALPHABETS[$rule['characters']]);
        // Digits can fall below the minimum, such as 00 for an LGA, so the loop draws again.
        do {
            $text = '';
            for ($place = 0; $place < $rule['length']; ++$place) {
                $text .= self::pick($alphabet);
            }
        } while ((int) $text < ($rule['minimum'] ?? 0));

        return $text;
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return T
     */
    private static function pick(array $items): mixed
    {
        return $items[\mt_rand(0, \count($items) - 1)];
    }
}
