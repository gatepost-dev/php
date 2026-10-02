<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use FilesystemIterator;
use Gatepost\Postcode\Tests\ScriptRun;
use Gatepost\Postcode\Tests\TemporaryFolder;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The harness for the tests of scripts/dump-api.php. The script reads its repo from its own
 * place, so each test copies it into a temporary repo. The repo holds the classes of
 * tests/Fixtures/ApiDump as its src folder, and a composer.json that maps them.
 */
abstract class DumpApiTestCase extends TestCase
{
    protected const DUMP = 'docs/api-dump.md';
    protected const FIXTURE_NAMESPACE = 'Gatepost\\Postcode\\Tests\\Fixtures\\ApiDump';

    protected string $repo;

    protected function setUp(): void
    {
        $this->repo = TemporaryFolder::create('gatepost-dump-api-');
        \mkdir("{$this->repo}/scripts");
        \mkdir("{$this->repo}/docs");
        \copy(\dirname(__DIR__, 2) . '/scripts/dump-api.php', "{$this->repo}/scripts/dump-api.php");
        \file_put_contents("{$this->repo}/composer.json", \json_encode([
            'name' => 'fixture/api',
            'autoload' => ['psr-4' => [self::FIXTURE_NAMESPACE . '\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        self::copyFixtureClasses(\dirname(__DIR__) . '/Fixtures/ApiDump', "{$this->repo}/src");
    }

    protected function tearDown(): void
    {
        TemporaryFolder::remove($this->repo);
    }

    protected function dump(string ...$arguments): ScriptRun
    {
        return ScriptRun::of(\PHP_BINARY, "{$this->repo}/scripts/dump-api.php", ...$arguments);
    }

    protected function dumpFile(): string
    {
        $text = \file_get_contents("{$this->repo}/" . self::DUMP);
        self::assertIsString($text);

        return $text;
    }

    /**
     * Copies each PHP file of a folder, with its place in the folder. The expected dump stays
     * behind, because it is not a class.
     */
    private static function copyFixtureClasses(string $from, string $to): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $target = $to . \substr($file->getPathname(), \strlen($from));
            if (!\is_dir(\dirname($target))) {
                \mkdir(\dirname($target), 0o777, true);
            }
            \copy($file->getPathname(), $target);
        }
    }
}
