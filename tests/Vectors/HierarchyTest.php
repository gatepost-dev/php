<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Vectors;

use Gatepost\Postcode\Precision;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HierarchyTest extends TestCase
{
    /**
     * @return array<string, array{VectorCase}>
     */
    public static function truncateVectors(): array
    {
        return VectorFile::cases('truncate', 'truncate');
    }

    /**
     * @return array<string, array{VectorCase}>
     */
    public static function parentVectors(): array
    {
        return VectorFile::cases('parent', 'parent');
    }

    /**
     * @return array<string, array{VectorCase}>
     */
    public static function containsVectors(): array
    {
        return VectorFile::cases('contains', 'contains');
    }

    #[Test]
    #[DataProvider('truncateVectors')]
    public function truncatesAsTheVectorSays(VectorCase $vector): void
    {
        $code = $vector->postcode('code');
        $to = Precision::from($vector->field('to'));
        if (\array_key_exists('rejects', $vector->expected())) {
            $this->expectException(InvalidArgumentException::class);
            $code->truncate($to);

            return;
        }
        $canonical = $code->truncate($to)->canonical;

        self::assertSame($vector->expectedField('canonical'), $canonical);
    }

    #[Test]
    #[DataProvider('parentVectors')]
    public function findsTheParentAsTheVectorSays(VectorCase $vector): void
    {
        $parent = $vector->postcode('code')->parent();

        self::assertSame($vector->expectedField('canonical'), $parent?->canonical);
    }

    #[Test]
    #[DataProvider('containsVectors')]
    public function findsContainedCodesAsTheVectorSays(VectorCase $vector): void
    {
        $contains = $vector->postcode('prefix')->contains($vector->postcode('code'));

        self::assertSame($vector->expectedField('value'), $contains);
    }
}
