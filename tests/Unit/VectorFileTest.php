<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Tests\Vectors\VectorFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Every vector test trusts VectorFile. A loader that accepted a broken file would let a vector go
 * unrun, so these tests give the loader the small files in tests/Fixtures/vectors.
 */
final class VectorFileTest extends TestCase
{
    /**
     * VectorFile reads spec/vectors/<name>.json, so two steps up reach the repo root.
     */
    private const FIXTURES = '../../tests/Fixtures/vectors/';

    #[Test]
    public function namesEachCaseByItsIdAndItsDescription(): void
    {
        $cases = VectorFile::cases(self::FIXTURES . 'valid', 'stateName');

        self::assertSame(
            ['state-name-001 names EK', 'state-name-002 returns nothing for an unknown code'],
            \array_keys($cases),
        );
        self::assertSame('EK', $cases['state-name-001 names EK'][0]->text());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function brokenFiles(): array
    {
        return [
            'a wrong format version' => ['wrong-version', 'stateName', 'format version 1'],
            'a function that the file does not test' => ['valid', 'parse', 'does not test parse'],
            'an empty case list' => ['no-cases', 'stateName', 'has no cases'],
            'one id on two cases' => ['repeated-id', 'stateName', 'the id state-name-001 twice'],
            'two cases with one name' => ['same-name', 'stateName', 'the name a b c'],
        ];
    }

    #[Test]
    #[DataProvider('brokenFiles')]
    public function rejectsABrokenFile(string $file, string $function, string $reason): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($reason);

        VectorFile::cases(self::FIXTURES . $file, $function);
    }

    #[Test]
    public function explainsAMissingFileWithoutAPhpWarning(): void
    {
        $warnings = [];
        \set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        $error = null;
        try {
            VectorFile::cases(self::FIXTURES . 'absent', 'stateName');
        } catch (UnexpectedValueException $caught) {
            $error = $caught;
        } finally {
            \restore_error_handler();
        }

        self::assertInstanceOf(UnexpectedValueException::class, $error);
        self::assertStringContainsString('Cannot read', $error->getMessage());
        self::assertSame([], $warnings);
    }
}
