<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Tests\Vectors\VectorFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * T-2 asks that every vector file runs, and PAR-1 ties the claimed spec version to that. Each
 * runner names its own file, so nothing else would notice a new file in spec/vectors, such as the
 * split of the parse vectors into three files. These tests compare VectorFile::NAMES with the
 * folder and with the runners.
 */
final class VectorFileListTest extends TestCase
{
    #[Test]
    public function namesEachFileThatSpecVectorsHolds(): void
    {
        $unlisted = \array_values(\array_diff(self::filesInSpec(), VectorFile::NAMES));

        self::assertSame(
            [],
            $unlisted,
            'VectorFile::NAMES lacks ' . \implode(', ', $unlisted) . ', which spec/vectors holds. '
            . 'Write a runner in tests/Vectors, and add the file name to the list.',
        );
    }

    #[Test]
    public function namesOnlyFilesThatSpecVectorsHolds(): void
    {
        $absent = \array_values(\array_diff(VectorFile::NAMES, self::filesInSpec()));

        self::assertSame(
            [],
            $absent,
            'VectorFile::NAMES names ' . \implode(', ', $absent) . ', but spec/vectors lacks '
            . 'the file. Rename or remove the name and its runner.',
        );
    }

    #[Test]
    public function hasARunnerThatLoadsEachListedFile(): void
    {
        $loaded = [];
        foreach (self::runnerCode() as $code) {
            \preg_match_all("/VectorFile::cases\(\s*'([^']+)'/", $code, $names);
            $loaded = [...$loaded, ...$names[1]];
        }
        $idle = \array_values(\array_diff(VectorFile::NAMES, $loaded));

        self::assertSame(
            [],
            $idle,
            'VectorFile::NAMES names ' . \implode(', ', $idle) . ', but no runner loads the '
            . 'file. Write the runner, or remove the name. A runner names its file as a string '
            . 'in a call of VectorFile::cases().',
        );
    }

    /**
     * @return list<string> The name of each .json file in spec/vectors, without the extension.
     */
    private static function filesInSpec(): array
    {
        $paths = \glob(\dirname(__DIR__, 2) . '/spec/vectors/*.json');
        self::assertIsArray($paths);
        self::assertNotSame([], $paths, 'spec/vectors holds no file. Check out the submodule.');
        $names = \array_map(static fn(string $path): string => \basename($path, '.json'), $paths);
        \sort($names);

        return $names;
    }

    /**
     * The code of each runner, without its comments. A call that someone commented out loads
     * no file, so it must not count.
     *
     * @return list<string>
     */
    private static function runnerCode(): array
    {
        $paths = \glob(\dirname(__DIR__) . '/Vectors/*Test.php');
        self::assertIsArray($paths);
        $runners = [];
        foreach ($paths as $path) {
            $source = \file_get_contents($path);
            self::assertIsString($source);
            $code = '';
            foreach (\token_get_all($source) as $token) {
                $isComment = \is_array($token)
                    && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true);
                $code .= match (true) {
                    $isComment => '',
                    \is_array($token) => $token[1],
                    default => $token,
                };
            }
            $runners[] = $code;
        }

        return $runners;
    }
}
