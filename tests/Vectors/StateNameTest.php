<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Vectors;

use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StateNameTest extends TestCase
{
    /**
     * @return array<string, array{VectorCase}>
     */
    public static function vectors(): array
    {
        return VectorFile::cases('state-name', 'stateName');
    }

    #[Test]
    #[DataProvider('vectors')]
    public function namesTheStateAsTheVectorSays(VectorCase $vector): void
    {
        self::assertSame($vector->expectedField('value'), Postcode::stateName($vector->text()));
    }
}
