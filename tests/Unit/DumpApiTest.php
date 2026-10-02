<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;

/**
 * NPG-5 asks for an API dump, and GIT-7 asks that a change of the public interface updates it.
 * These tests compare the dump of the classes in tests/Fixtures/ApiDump with the file expected.md
 * next to them, and they check that the check notices a change.
 */
final class DumpApiTest extends DumpApiTestCase
{
    #[Test]
    public function writesTheSymbolsThatCallersCanUseAndNoOtherSymbol(): void
    {
        $run = $this->dump();

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame('', $run->output);
        $expected = \file_get_contents(\dirname(__DIR__) . '/Fixtures/ApiDump/expected.md');
        self::assertSame($expected, $this->dumpFile());
    }

    #[Test]
    public function passesTheCheckWhenTheFileIsCurrent(): void
    {
        $this->dump();

        $run = $this->dump('--check');

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame('', $run->output);
    }

    #[Test]
    public function failsTheCheckWhenAPublicMethodChangedAfterTheLastWrite(): void
    {
        $this->dump();
        $square = "{$this->repo}/src/Square.php";
        $code = \file_get_contents($square);
        self::assertIsString($code);
        \file_put_contents($square, \str_replace('function join(', 'function glue(', $code));
        $before = $this->dumpFile();

        $run = $this->dump('--check');

        self::assertSame(1, $run->exitCode);
        self::assertSame(self::DUMP . ' is out of date. Run composer api:dump.', $run->output);
        self::assertSame($before, $this->dumpFile());
    }

    #[Test]
    public function failsTheCheckWhenSomeoneEditedTheFile(): void
    {
        $this->dump();
        \file_put_contents("{$this->repo}/" . self::DUMP, "\nA line from a person.\n", FILE_APPEND);

        $run = $this->dump('--check');

        self::assertSame(1, $run->exitCode);
        self::assertSame(self::DUMP . ' is out of date. Run composer api:dump.', $run->output);
    }

    #[Test]
    public function failsTheCheckWhenTheFileDoesNotExist(): void
    {
        $run = $this->dump('--check');

        self::assertSame(1, $run->exitCode);
        self::assertSame(self::DUMP . ' is out of date. Run composer api:dump.', $run->output);
        self::assertFileDoesNotExist("{$this->repo}/" . self::DUMP);
    }
}
