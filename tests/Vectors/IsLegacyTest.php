<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Vectors;

use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IsLegacyTest extends TestCase
{
    /**
     * @return array<string, array{VectorCase}>
     */
    public static function vectors(): array
    {
        return VectorFile::cases('is-legacy', 'isLegacy');
    }

    #[Test]
    #[DataProvider('vectors')]
    public function findsLegacyCodesAsTheVectorSays(VectorCase $vector): void
    {
        self::assertSame($vector->expectedField('value'), Postcode::isLegacy($vector->text()));
    }
}
