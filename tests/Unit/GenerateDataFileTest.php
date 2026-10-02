<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The problems of the data files themselves: a syntax error, a value that appears twice, and a
 * file that no user can read. GenerateDataTest holds the problems of the content. The generated
 * class is an array, so a repeated key would keep only the last value and hide the mistake.
 */
final class GenerateDataFileTest extends GeneratorTestCase
{
    /**
     * @return array<string, array{Closure(string): void, string}> Each row breaks one file and
     *                                                              gives the reason that must show.
     */
    public static function brokenFiles(): array
    {
        $format = 'spec/data/format.json';
        $states = 'spec/data/states.json';

        return [
            'a data file that is not JSON' => [
                self::edit('spec/data/precision.json', '"version": 1', '"version": '),
                'spec/data/precision.json is not valid JSON: Syntax error.',
            ],
            'a state code that appears twice' => [
                self::edit($states, '"code": "LA"', '"code": "EK"'),
                'spec/data/states.json: the state code EK appears more than once.',
            ],
            'a segment name that appears twice' => [
                self::edit($format, '"name": "lga"', '"name": "state"'),
                'spec/data/format.json: the segment name state appears more than once.',
            ],
            'a separator that appears twice' => [
                self::edit($format, '"U+0020"', '"U+002D"'),
                'spec/data/format.json: the separator U+002D appears more than once.',
            ],
            // The pattern of a label allows 4 to 6 digits, so U+002D has two more spellings.
            'two labels for one separator' => [
                self::edit($format, '"U+0020"', '"U+00002D"'),
                'spec/data/format.json: the separator U+002D appears more than once.',
            ],
            'a data file that no user can read' => [
                static function (string $root) use ($states): void {
                    \chmod("{$root}/{$states}", 0o000);
                    if (\is_readable("{$root}/{$states}")) {
                        self::markTestSkipped('This user can read each file, as root can.');
                    }
                },
                'Cannot read',
            ],
        ];
    }

    /**
     * @param Closure(string): void $breakFile
     */
    #[Test]
    #[DataProvider('brokenFiles')]
    public function stopsOnABrokenFileWithItsNameAndNoPhpWarning(
        Closure $breakFile,
        string $reason,
    ): void {
        $this->assertStopsOnBrokenData($breakFile, $reason);
    }
}
