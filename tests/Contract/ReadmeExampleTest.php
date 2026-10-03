<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Contract;

use Gatepost\Postcode\Tests\Readme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * DOC-3 asks that CI runs each code example of the README. The client example calls the gateway,
 * so this test points it at the mock server and runs it with a mock key. It changes nothing else
 * in the example.
 */
final class ReadmeExampleTest extends MockServerTestCase
{
    private const FACTORY_LINE = "    new HttpFactory(),\n";

    /**
     * The heading of each section with a client example, and the text that the example prints.
     *
     * @return array<string, array{string, string}>
     */
    public static function examples(): array
    {
        return [
            'the check of a postcode' => ['Check a postcode with the gateway', "valid\n"],
            'the search for a place' => [
                'Find a place and complete a postcode',
                "FC-01-Z99-ZZ-01\ndistrict\n",
            ],
        ];
    }

    #[Test]
    #[DataProvider('examples')]
    public function runsEachClientExampleAgainstTheMockServer(
        string $heading,
        string $printed,
    ): void {
        $example = Readme::phpExample(Readme::section($heading));
        self::assertSame(1, \substr_count($example, self::FACTORY_LINE));
        $baseUrl = '    baseUrl: ' . \var_export($this->mockUrl, true) . ",\n";
        \putenv('NIPOST_API_KEY=nipost_test_mock_l1');
        $this->expectOutputString($printed);

        try {
            Readme::run(\str_replace(self::FACTORY_LINE, self::FACTORY_LINE . $baseUrl, $example));
        } finally {
            \putenv('NIPOST_API_KEY');
        }
    }
}
