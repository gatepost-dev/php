<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Vectors;

use Gatepost\Postcode\ParseResult;
use Gatepost\Postcode\Postcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParseTest extends TestCase
{
    /**
     * @return array<string, array{VectorCase}>
     */
    public static function vectors(): array
    {
        return [
            ...VectorFile::cases('parse', 'parse'),
            ...VectorFile::cases('parse-segments', 'parse'),
            ...VectorFile::cases('parse-states', 'parse'),
        ];
    }

    #[Test]
    #[DataProvider('vectors')]
    public function parsesAsTheVectorSays(VectorCase $vector): void
    {
        $result = Postcode::parse($vector->text(), allowPartial: $vector->allowPartial());

        self::assertSame($vector->expected(), self::asVector($result));
    }

    /**
     * Writes a result in the shape of a vector's expect field. The keys follow the order in
     * spec/vectors/README.md, because assertSame() compares the order of keys too.
     *
     * @return array<string, mixed>
     */
    private static function asVector(ParseResult $result): array
    {
        if (!$result->isOk()) {
            $error = $result->error;

            return [
                'ok' => false,
                'error' => [
                    'code' => $error->code->value,
                    'segment' => $error->segment?->value,
                    'suggestion' => $error->suggestion,
                ],
            ];
        }
        $postcode = $result->value;

        return [
            'ok' => true,
            'compact' => $postcode->compact,
            'canonical' => $postcode->canonical,
            'display' => $postcode->display,
            'precision' => $postcode->precision->value,
            'segments' => $postcode->segments->toArray(),
        ];
    }
}
