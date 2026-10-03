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
