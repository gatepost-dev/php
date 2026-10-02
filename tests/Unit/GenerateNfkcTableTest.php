<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Tests\ScriptRun;
use PHPUnit\Framework\Attributes\Test;

/**
 * The parts of the generator that come from the Unicode data: the notice in the header of the
 * table, and the download of UnicodeData.txt. A download test removes the cached copy and starts
 * the generator with a stand-in in tests/Fixtures/generator, which serves a local file where the
 * generator expects the network.
 */
final class GenerateNfkcTableTest extends GeneratorTestCase
{
    #[Test]
    public function givesTheTableTheUnicodeNoticeAndTheSpecDataOnlyTheGatepostNotice(): void
    {
        // Inside this file, these tags end in a quote and a comma, so REUSE rejects them unless
        // it skips them. The Unicode licence asks that its notice stay with each copy of the data.
        // REUSE-IgnoreStart
        $tableTags = [
            '// SPDX-FileCopyrightText: 2026 The Gatepost authors',
            '// SPDX-FileCopyrightText: 1991-2026 Unicode, Inc.',
            '// SPDX-License-Identifier: Apache-2.0 AND Unicode-3.0',
        ];
        $specDataTags = [
            '// SPDX-FileCopyrightText: 2026 The Gatepost authors',
            '// SPDX-License-Identifier: Apache-2.0',
        ];
        // REUSE-IgnoreEnd

        $this->generate();
        $classes = $this->generatedClasses();

        self::assertSame($tableTags, self::spdxLines($classes[self::NFKC_TABLE] ?? ''));
        self::assertSame($specDataTags, self::spdxLines($classes[self::SPEC_DATA] ?? ''));
    }

    #[Test]
    public function marksEachClassAsGeneratedWithinItsFirstFiveLines(): void
    {
        $this->generate();

        $classes = $this->generatedClasses();

        self::assertSame(self::GENERATED, \array_keys($classes));
        foreach ($classes as $path => $class) {
            // check-tells reads only these lines to exempt a generated file from TELL-1. The
            // three tags of the table would push the marker past them if the marker came last.
            $head = \implode("\n", \array_slice(\explode("\n", $class), 0, 5));
            self::assertStringContainsString('@generated', $head, "{$path} has no marker.");
        }
    }

    #[Test]
    public function cachesADownloadThatMatchesItsHash(): void
    {
        $unicodeData = self::cachedUnicodeData();

        $run = $this->generateAfterDownloading($unicodeData);

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame('', $run->output);
        self::assertSame(
            \hash('sha256', $unicodeData),
            \hash_file('sha256', "{$this->root}/" . self::UNICODE_DATA),
        );
        self::assertSame(self::GENERATED, \array_keys($this->generatedClasses()));
    }

    #[Test]
    public function cachesNothingWhenADownloadFailsItsHash(): void
    {
        $partial = \substr(self::cachedUnicodeData(), 0, 1000);

        $run = $this->generateAfterDownloading($partial);

        self::assertNotSame(0, $run->exitCode, $run->output);
        self::assertStringContainsString('does not match its SHA-256 hash', $run->output);
        // A cached file that fails its hash would stop each later run until someone deleted it.
        self::assertFileDoesNotExist("{$this->root}/" . self::UNICODE_DATA);
        self::assertSame([], $this->generatedClasses());
    }

    /**
     * Removes the cached UnicodeData.txt, so that the generator has to download it. The stand-in
     * serves the given bytes for that download.
     */
    private function generateAfterDownloading(string $served): ScriptRun
    {
        \unlink("{$this->root}/" . self::UNICODE_DATA);
        self::assertNotFalse(\file_put_contents("{$this->root}/served-download", $served));
        $standIn = "{$this->root}/serve-download.php";
        \copy(\dirname(__DIR__) . '/Fixtures/generator/serve-download.php', $standIn);

        return ScriptRun::of(
            \PHP_BINARY,
            '-d',
            'display_errors=1',
            '-d',
            "auto_prepend_file={$standIn}",
            "{$this->root}/scripts/generate-data.php",
        );
    }

    private static function cachedUnicodeData(): string
    {
        $unicodeData = \file_get_contents(\dirname(__DIR__, 2) . '/' . self::UNICODE_DATA);
        self::assertIsString($unicodeData);

        return $unicodeData;
    }

    /**
     * @return list<string> The SPDX tags in the header of a written class.
     */
    private static function spdxLines(string $class): array
    {
        $lines = \explode("\n", $class);

        return \array_values(
            \array_filter($lines, static fn(string $line): bool => \str_contains($line, 'SPDX-')),
        );
    }
}
