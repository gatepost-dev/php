<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Internal\NfkcTable;
use Gatepost\Postcode\Internal\SpecData;
use Gatepost\Postcode\ParseResult;
use Gatepost\Postcode\Postcode;
use Gatepost\Postcode\Precision;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Checks rules that must hold for every input, on many generated inputs. PHP has no standard
 * property-testing tool, so a seeded Mersenne Twister makes the inputs. The fixed seed gives the
 * same inputs in each run, so a failure always repeats.
 *
 * Under Xdebug path coverage, each assertion costs a few milliseconds, and the cost grows with
 * the number of assertions that the run has made. A loop with several assertions in each of 500
 * runs more than doubled the time of composer check. So the properties with the most checks
 * collect the inputs that break the rule, and the test asserts once.
 */
final class PropertiesTest extends TestCase
{
    private const SEED = 20261002;
    private const RUNS = 500;
    private const SHOWN_VIOLATIONS = 5;
    // The pool of the third property holds this many codes, each with a typo and an old code.
    private const CODE_PIECES = 20;
    private const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const DIGITS = '0123456789';
    private const ALPHABETS = [
        'letters' => self::LETTERS,
        'digits' => self::DIGITS,
        'letters-or-digits' => self::LETTERS . self::DIGITS,
    ];

    protected function setUp(): void
    {
        \mt_srand(self::SEED);
    }

    #[Test]
    public function parsesEveryValidCodeAndKeepsItsFormsInStep(): void
    {
        $violations = [];
        for ($run = 0; $run < self::RUNS; ++$run) {
            $compact = self::validCode();
            $postcode = Postcode::parse($compact)->value;

            if (!self::formsAreInStep($postcode, $compact)) {
                $violations[] = $postcode === null
                    ? "{$compact} did not parse"
                    : "{$compact} gave {$postcode->canonical} and {$postcode->display}";
            }
        }

        self::assertNoViolations($violations, 'A valid code did not parse, or has wrong forms.');
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
        $separators = \array_keys(SpecData::SEPARATORS);
        $noise = [
            ...\str_split(self::LETTERS . self::DIGITS),
            ...$separators,
            ...\array_keys(NfkcTable::FORMS),
            "\u{0301}", "\u{00B5}", "\u{202E}", "\u{1F600}", "\xFF", "\xC3", "\xED\xA0\x80",
        ];
        $nearCodes = [...self::codePieces(), ...$separators];
        for ($run = 0; $run < self::RUNS; ++$run) {
            // A long text of noise fails an early check. A short text of codes, typos and
            // separators passes the early checks, so it reaches the later checks, and some of
            // these texts parse.
            $text = \mt_rand(0, 1) === 0
                ? self::randomText($noise, \mt_rand(0, 80))
                : self::randomText($nearCodes, \mt_rand(1, 3));

            $result = self::parseOrFail($text, \mt_rand(0, 1) === 1);

            self::assertNotSame($result->value === null, $result->error === null, \bin2hex($text));
        }
    }

    #[Test]
    public function suggestsOnlyCodesThatParse(): void
    {
        $suggestions = [];
        for ($run = 0; $run < self::RUNS; ++$run) {
            $typo = self::withTypo(self::validCode());
            $suggestion = Postcode::parse($typo)->error?->suggestion;
            if ($suggestion !== null) {
                $suggestions[$typo] = $suggestion;
            }
        }

        self::assertNotEmpty(
            $suggestions,
            'No typo gave a suggestion, so the property checked nothing.',
        );
        foreach ($suggestions as $typo => $suggestion) {
            self::assertTrue(Postcode::parse($suggestion)->isOk(), "{$typo} gave {$suggestion}");
        }
    }

    #[Test]
    public function normalisesAsciiTextCompletelyInOnePass(): void
    {
        // A one-byte separator is an ASCII character, and the text holds only ASCII characters.
        $asciiSeparators = \implode('', \array_filter(
            \array_keys(SpecData::SEPARATORS),
            static fn(string $separator): bool => \strlen($separator) === 1,
        ));
        $violations = [];
        for ($run = 0; $run < self::RUNS; ++$run) {
            $text = '';
            for ($count = \mt_rand(0, 40); $count > 0; --$count) {
                $text .= \chr(\mt_rand(0, 0x7F));
            }
            $once = Postcode::normalize($text);

            if ($once !== Postcode::normalize($once)
                || \preg_match('/[a-z]/', $once) === 1
                || \strpbrk($once, $asciiSeparators) !== false) {
                $violations[] = \bin2hex($text) . ' gave ' . \bin2hex($once);
            }
        }

        self::assertNoViolations($violations, 'A text, in hex, was not normalised in one pass.');
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
     * Whole codes cut after a random segment, a typo of each, and old 6-digit codes. A code that
     * stops after a segment parses only with allowPartial.
     *
     * @return list<string>
     */
    private static function codePieces(): array
    {
        $ends = \array_column(SpecData::SEGMENTS, 'end');
        $pieces = [];
        for ($index = 0; $index < self::CODE_PIECES; ++$index) {
            $code = \substr(self::validCode(), 0, self::pick($ends));
            $pieces[] = $code;
            $pieces[] = self::withTypo($code);
            $pieces[] = \sprintf('%06d', \mt_rand(0, 999999));
        }

        return $pieces;
    }

    private static function withTypo(string $code): string
    {
        $place = \mt_rand(0, \strlen($code) - 1);

        return \substr_replace($code, self::pick(self::lookAlikes()), $place, 1);
    }

    /**
     * The characters that the fix tables of the spec change. A fix that the spec adds later then
     * gets tested too.
     *
     * @return list<string>
     */
    private static function lookAlikes(): array
    {
        $keys = [...\array_keys(SpecData::DIGIT_FIXES), ...\array_keys(SpecData::LETTER_FIXES)];

        // PHP turns the keys 0 and 1 of the letter table into integers.
        return \array_map(\strval(...), $keys);
    }

    /**
     * PHPUnit prints no input when parse throws. The property says that parse never throws, so
     * each throw fails the test, and the failure names the input.
     */
    private static function parseOrFail(string $text, bool $allowPartial): ParseResult
    {
        try {
            return Postcode::parse($text, allowPartial: $allowPartial);
        } catch (Throwable $thrown) {
            throw new AssertionFailedError(
                \sprintf('parse threw %s. The input in hex: %s', $thrown::class, \bin2hex($text)),
                0,
                $thrown,
            );
        }
    }

    /**
     * @param list<string> $pieces
     */
    private static function randomText(array $pieces, int $count): string
    {
        $text = '';
        for ($piece = 0; $piece < $count; ++$piece) {
            $text .= self::pick($pieces);
        }

        return $text;
    }

    /**
     * A failure shows only the first violations, because a mistake in the code can break each run.
     *
     * @param list<string> $violations
     */
    private static function assertNoViolations(array $violations, string $rule): void
    {
        $count = \count($violations);
        $message = \sprintf('%d of %d runs broke this rule. %s', $count, self::RUNS, $rule);

        self::assertSame([], \array_slice($violations, 0, self::SHOWN_VIOLATIONS), $message);
    }

    // The same characters can sit in the wrong places, so the segment lengths count too.
    private static function formsAreInStep(?Postcode $postcode, string $compact): bool
    {
        $shape = \implode(',', \array_column(SpecData::SEGMENTS, 'length'));

        return $postcode !== null
            && $postcode->compact === $compact
            && \str_replace('-', '', $postcode->canonical) === $compact
            && \str_replace(' ', '', $postcode->display) === $compact
            && self::segmentShape('-', $postcode->canonical) === $shape
            && self::segmentShape(' ', $postcode->display) === $shape;
    }

    /**
     * The length of each segment of a form, such as 2,2,3,2,2.
     *
     * @param non-empty-string $separator
     */
    private static function segmentShape(string $separator, string $form): string
    {
        return \implode(',', \array_map(\strlen(...), \explode($separator, $form)));
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
