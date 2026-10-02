<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Tests\ScriptRun;
use Gatepost\Postcode\Tests\TemporaryFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DEP-4 asks for a permissive licence in each dependency, and no tool checked it. The check rests
 * on scripts/check-licences.php, so these tests run the script on the small lock files in
 * tests/Fixtures/licences.
 */
final class CheckLicencesTest extends TestCase
{
    private const FIXTURES = 'tests/Fixtures/licences/';

    private string $repo = '';

    protected function tearDown(): void
    {
        if ($this->repo !== '') {
            TemporaryFolder::remove($this->repo);
        }
    }

    #[Test]
    public function acceptsPackagesWithAnAllowedLicenceOrAnAllowedAlternative(): void
    {
        $run = self::check('allowed.json');

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame(
            self::FIXTURES . 'allowed.json: 11 packages, each with an allowed licence.',
            $run->output,
        );
    }

    #[Test]
    public function namesEachPackageWhoseLicenceIsNotAllowed(): void
    {
        $file = self::FIXTURES . 'refused.json';

        $run = self::check('refused.json');

        self::assertSame(1, $run->exitCode);
        $lines = \explode("\n", $run->output);
        self::assertStringContainsString('DEP-4', \array_pop($lines));
        self::assertSame(
            [
                "{$file}: example/copyleft 1.0.0 has the licence GPL-3.0-only.",
                "{$file}: example/network 1.1.0 has the licence AGPL-3.0-only.",
                "{$file}: example/weak 1.2.0 has the licence MPL-2.0.",
                "{$file}: example/closed 1.3.0 has the licence proprietary.",
                "{$file}: example/no-choice 2.0.0 has the licence GPL-3.0-only or LGPL-3.0-only.",
                "{$file}: example/both 2.1.0 has the licence (MIT and BSD-3-Clause).",
                "{$file}: example/similar 2.2.0 has the licence BSD-4-Clause.",
                "{$file}: example/silent 2.3.0 has no licence.",
                "{$file}: example/empty 2.4.0 has no licence.",
            ],
            $lines,
        );
    }

    #[Test]
    public function checksEachFileThatItGetsAndReportsOnlyTheFailures(): void
    {
        $run = self::check('allowed.json', 'refused.json');

        self::assertSame(1, $run->exitCode);
        self::assertStringContainsString('refused.json: example/copyleft 1.0.0', $run->output);
        self::assertStringNotContainsString('allowed.json', $run->output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusableLockFiles(): array
    {
        $dir = self::FIXTURES;

        return [
            'a file that does not exist' => ['absent.json', "Cannot read {$dir}absent.json."],
            'a file that is not JSON' => [
                'not-json.json',
                "{$dir}not-json.json is not valid JSON: Syntax error.",
            ],
            'a lock with no package' => [
                'no-packages.json',
                "{$dir}no-packages.json lists no package, so it proves nothing.",
            ],
            'a package with no name' => [
                'nameless.json',
                "{$dir}nameless.json holds a package that has no name or no version.",
            ],
        ];
    }

    #[Test]
    #[DataProvider('unusableLockFiles')]
    public function failsOnALockFileThatItCannotUse(string $fixture, string $message): void
    {
        $run = self::check($fixture);

        self::assertSame(2, $run->exitCode);
        self::assertSame($message, $run->output);
    }

    #[Test]
    public function failsOnALockFileThatNoUserCanRead(): void
    {
        $lock = \tempnam(\sys_get_temp_dir(), 'gatepost-lock-');
        self::assertIsString($lock);

        try {
            \copy(\dirname(__DIR__) . '/Fixtures/licences/allowed.json', $lock);
            \chmod($lock, 0o000);
            if (\is_readable($lock)) {
                self::markTestSkipped('This user can read each file, as root can.');
            }

            $run = ScriptRun::of(\PHP_BINARY, 'scripts/check-licences.php', $lock);
        } finally {
            \chmod($lock, 0o600);
            \unlink($lock);
        }

        self::assertSame(2, $run->exitCode);
        self::assertSame("Cannot read {$lock}.", $run->output);
    }

    #[Test]
    public function readsComposerLockAndEachToolLockWhenItGetsNoFile(): void
    {
        $this->startRepo('composer.lock', 'tools/alpha/composer.lock', 'tools/beta/composer.lock');

        $run = $this->checkRepo();

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame(
            [
                'composer.lock: 11 packages, each with an allowed licence.',
                'tools/alpha/composer.lock: 11 packages, each with an allowed licence.',
                'tools/beta/composer.lock: 11 packages, each with an allowed licence.',
            ],
            \explode("\n", $run->output),
        );
    }

    #[Test]
    public function failsWhenTheLockOfAToolProjectBreaksThePolicy(): void
    {
        $this->startRepo('composer.lock', 'tools/alpha/composer.lock');
        $refused = \dirname(__DIR__) . '/Fixtures/licences/refused.json';
        \copy($refused, "{$this->repo}/tools/alpha/composer.lock");

        $run = $this->checkRepo();

        self::assertSame(1, $run->exitCode);
        self::assertStringContainsString(
            'tools/alpha/composer.lock: example/copyleft 1.0.0',
            $run->output,
        );
        self::assertStringNotContainsString("\ncomposer.lock", "\n" . $run->output);
    }

    #[Test]
    public function failsWhenTheRepoHasNoComposerLock(): void
    {
        $this->startRepo();

        $run = $this->checkRepo();

        self::assertSame(2, $run->exitCode);
        self::assertSame('Cannot read composer.lock.', $run->output);
    }

    private static function check(string ...$fixtures): ScriptRun
    {
        $paths = \array_map(static fn(string $name): string => self::FIXTURES . $name, $fixtures);

        return ScriptRun::of(\PHP_BINARY, 'scripts/check-licences.php', ...$paths);
    }

    /**
     * Makes a repo with a copy of the script and the given lock files, each of which holds the
     * packages of allowed.json. The script finds its lock files from its own place, so the copy
     * checks the repo that the test made.
     */
    private function startRepo(string ...$locks): void
    {
        $this->repo = TemporaryFolder::create('gatepost-licences-');
        \mkdir("{$this->repo}/scripts");
        $script = \dirname(__DIR__, 2) . '/scripts/check-licences.php';
        \copy($script, "{$this->repo}/scripts/check-licences.php");
        foreach ($locks as $lock) {
            $folder = \dirname("{$this->repo}/{$lock}");
            if (!\is_dir($folder)) {
                \mkdir($folder, 0o777, true);
            }
            \copy(\dirname(__DIR__) . '/Fixtures/licences/allowed.json', "{$this->repo}/{$lock}");
        }
    }

    private function checkRepo(): ScriptRun
    {
        return ScriptRun::of(\PHP_BINARY, "{$this->repo}/scripts/check-licences.php");
    }
}
