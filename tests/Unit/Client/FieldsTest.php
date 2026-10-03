<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit\Client;

use Gatepost\Postcode\Client\Internal\Fields;
use Gatepost\Postcode\Client\PostcodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * json_decode() gives an array for a JSON object and for a JSON list alike, so the reader of a
 * response must tell them apart before it reads a field.
 */
final class FieldsTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function objects(): array
    {
        return [
            'an object with fields' => [['valid' => true]],
            'an empty object, which json_decode() reads as an empty array' => [[]],
        ];
    }

    #[Test]
    #[DataProvider('objects')]
    public function readsAJsonObject(mixed $value): void
    {
        self::assertSame($value, Fields::object($value));
    }

    #[Test]
    public function readsAnObjectFieldAsNullWhenItIsNotAnObject(): void
    {
        $fields = ['a' => ['x' => 1], 'b' => 'text', 'c' => [1], 'd' => null];

        self::assertSame(['x' => 1], Fields::objectOrNull($fields, 'a'));
        self::assertNull(Fields::objectOrNull($fields, 'b'));
        self::assertNull(Fields::objectOrNull($fields, 'c'));
        self::assertNull(Fields::objectOrNull($fields, 'd'));
        self::assertNull(Fields::objectOrNull($fields, 'missing'));
    }

    #[Test]
    public function readsATextFieldAsNullWhenItIsNotText(): void
    {
        $fields = ['a' => 'x', 'b' => 5, 'c' => ['x'], 'd' => null];

        self::assertSame('x', Fields::textOrNull($fields, 'a'));
        self::assertNull(Fields::textOrNull($fields, 'b'));
        self::assertNull(Fields::textOrNull($fields, 'c'));
        self::assertNull(Fields::textOrNull($fields, 'd'));
        self::assertNull(Fields::textOrNull($fields, 'missing'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notObjects(): array
    {
        return [
            'a list' => [[1, 2]],
            'a list of one object' => [[['valid' => true]]],
            'text' => ['valid'],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('notObjects')]
    public function refusesAValueThatIsNotAJsonObject(mixed $value): void
    {
        $this->expectExceptionObject(PostcodeException::unreadable(200));

        Fields::object($value);
    }
}
