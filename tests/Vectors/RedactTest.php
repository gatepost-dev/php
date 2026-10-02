<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Vectors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedactTest extends TestCase
{
    /**
     * @return array<string, array{VectorCase}>
     */
    public static function vectors(): array
    {
        return VectorFile::cases('redact', 'redact');
    }

    #[Test]
    #[DataProvider('vectors')]
    public function hidesTheUnitAsTheVectorSays(VectorCase $vector): void
    {
        self::assertSame($vector->expectedField('value'), $vector->postcode('code')->redact());
    }
}
