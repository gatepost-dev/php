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
     * @return array<string, array{mixed, array<string, mixed>}>
     */
    public static function objects(): array
    {
        return [
            'an object with fields' => [['valid' => true], ['valid' => true]],
            'an empty object, which the Sender keeps as a stdClass' => [new \stdClass(), []],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('objects')]
    public function readsAJsonObject(mixed $value, array $expected): void
    {
        self::assertSame($expected, Fields::object($value));
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
        self::assertSame([], Fields::objectOrNull(['e' => new \stdClass()], 'e'));
        self::assertNull(Fields::objectOrNull(['e' => []], 'e'));
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
            'an empty list' => [[]],
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

    #[Test]
    public function readsAListAndRefusesAnythingElse(): void
    {
        self::assertSame([1, 'a'], Fields::list(['a' => [1, 'a']], 'a'));
        self::assertSame([], Fields::list(['a' => []], 'a'));
        foreach ([['a' => ['k' => 1]], ['a' => 'text'], ['a' => null], []] as $fields) {
            try {
                Fields::list($fields, 'a');
                self::fail('The reader accepted a value that is not a list.');
            } catch (PostcodeException $error) {
                self::assertEquals(PostcodeException::unreadable(200), $error);
            }
        }
    }

    #[Test]
    public function readsATextFieldAndRefusesAnythingElse(): void
    {
        self::assertSame('x', Fields::string(['a' => 'x'], 'a'));
        foreach ([['a' => 1], ['a' => null], []] as $fields) {
            try {
                Fields::string($fields, 'a');
                self::fail('The reader accepted a value that is not text.');
            } catch (PostcodeException $error) {
                self::assertEquals(PostcodeException::unreadable(200), $error);
            }
        }
    }

    #[Test]
    public function readsANumberAsAFloatAndGivesNullForAnythingElse(): void
    {
        $fields = ['i' => 4, 'f' => 4.5, 's' => '4', 'n' => null, 'big' => \INF, 'b' => true];

        self::assertSame(4.0, Fields::number($fields, 'i'));
        self::assertSame(4.5, Fields::numberOrNull($fields, 'f'));
        foreach (['s', 'n', 'big', 'b', 'missing'] as $name) {
            self::assertNull(Fields::numberOrNull($fields, $name));
        }
    }

    #[Test]
    public function refusesAFieldThatIsNotANumberWhenTheNumberIsRequired(): void
    {
        $this->expectExceptionObject(PostcodeException::unreadable(200));

        Fields::number(['a' => '4'], 'a');
    }
}
