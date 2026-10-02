<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\ParseError;
use Gatepost\Postcode\ParseErrorCode;
use Gatepost\Postcode\ParseResult;
use Gatepost\Postcode\Postcode;
use Gatepost\Postcode\Segments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class PostcodeTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function valueTypes(): array
    {
        return [
            'Postcode' => [Postcode::class],
            'Segments' => [Segments::class],
            'ParseResult' => [ParseResult::class],
            'ParseError' => [ParseError::class],
        ];
    }

    #[Test]
    public function claimsTheVersionOfTheSpecSubmodule(): void
    {
        $specVersion = \file_get_contents(\dirname(__DIR__, 2) . '/spec/VERSION');
        self::assertIsString($specVersion);

        self::assertSame(\trim($specVersion), Postcode::SPEC_VERSION);
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('valueTypes')]
    public function keepsEachValueTypeImmutable(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $writable = \array_filter(
            $reflection->getProperties(),
            static fn(ReflectionProperty $property): bool => !$property->isReadOnly(),
        );

        self::assertTrue($reflection->isFinal());
        self::assertTrue($constructor !== null && $constructor->isPrivate());
        self::assertSame([], $writable);
    }

    #[Test]
    public function rejectsAPartialCodeUnlessTheCallerAllowsOne(): void
    {
        self::assertSame(ParseErrorCode::BadLength, Postcode::parse('EK01A03')->error?->code);
    }
}
