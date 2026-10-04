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
 * scripts/check-api.sh starts the BC check from the newest release tag. These tests run the
 * script in a temporary repo with the tags of one case. A stub takes the place of the BC check
 * tool. It prints the tag that the script gave it, or the output of the case.
 */
final class CheckApiTest extends TestCase
{
    private const STUB = "#!/bin/sh\necho \"BC check from \${1#--from=}\"\n";

    private string $repo;

    protected function setUp(): void
    {
        $repo = TemporaryFolder::create('gatepost-api-');
        $this->repo = $repo;
        $stub = "{$repo}/tools/bc-check/vendor/bin/roave-backward-compatibility-check";
        \mkdir(\dirname($stub), 0o777, true);
        \file_put_contents($stub, self::STUB);
        \chmod($stub, 0o755);
    }

    protected function tearDown(): void
    {
        TemporaryFolder::remove($this->repo);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function tagsAndTheNewestOne(): array
    {
        return [
            'a release and its alpha' => [['v0.1.0-alpha.0', 'v0.1.0'], 'v0.1.0'],
            'the alpha of the next version' => [
                ['v0.1.0-alpha.0', 'v0.1.0', 'v0.1.1-alpha.0'],
                'v0.1.1-alpha.0',
            ],
            'versions with different numbers of digits' => [['v0.9.0', 'v0.10.0'], 'v0.10.0'],
        ];
    }

    /**
     * @param list<string> $tags
     */
    #[Test]
    #[DataProvider('tagsAndTheNewestOne')]
    public function startsTheBcCheckFromTheNewestTag(array $tags, string $newest): void
    {
        $this->startRepo(...$tags);

        $run = $this->checkApi();

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame("BC check from {$newest}", $run->output);
    }

    #[Test]
    public function stopsBeforeTheFirstTag(): void
    {
        $this->startRepo();

        $run = $this->checkApi();

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame(
            'No release tag yet, so there is no released API to compare.',
            $run->output,
        );
    }

    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function toolOutputs(): array
    {
        $constant = '[BC] CHANGED: Value of constant Gatepost\\Postcode\\Postcode::SPEC_VERSION '
            . "changed from '0.2.0' to '0.3.0'";
        $method = '[BC] REMOVED: Method Gatepost\\Postcode\\Postcode::check() was removed';
        $summary = static fn(int $count): string
            => "{$count} backwards-incompatible changes detected";

        return [
            'the constant alone' => [
                "{$constant}\n{$summary(1)}",
                3,
                0,
                '',
            ],
            'the constant and another break' => [
                "{$constant}\n{$method}\n{$summary(2)}",
                3,
                3,
                "{$method}\n{$summary(1)}",
            ],
            'another break alone' => [
                "{$method}\n{$summary(1)}",
                3,
                3,
                "{$method}\n{$summary(1)}",
            ],
            'a failure of the tool' => ['Could not read the tag', 1, 1, 'Could not read the tag'],
            'a break code with no finding' => ['Odd output', 3, 3, 'Odd output'],
        ];
    }

    #[Test]
    #[DataProvider('toolOutputs')]
    public function dropsOnlyTheChangeOfTheSpecVersion(
        string $toolOutput,
        int $toolCode,
        int $exitCode,
        string $output,
    ): void {
        $this->startRepo('v0.1.0');
        $stub = "{$this->repo}/tools/bc-check/vendor/bin/roave-backward-compatibility-check";
        \file_put_contents($stub, "#!/bin/sh\ncat <<'END'\n{$toolOutput}\nEND\nexit {$toolCode}\n");

        $run = $this->checkApi();

        self::assertSame($exitCode, $run->exitCode, $run->output);
        self::assertSame($output, $run->output);
    }

    // The repo starts in each test, so that tearDown() removes it even when a git command fails.
    private function startRepo(string ...$tags): void
    {
        $this->git('init', '--quiet');
        $this->git('commit', '--quiet', '--allow-empty', '--message', 'Start');
        foreach ($tags as $tag) {
            $this->git('tag', $tag);
        }
    }

    private function git(string ...$arguments): void
    {
        $run = ScriptRun::of(
            'git',
            '-C',
            $this->repo,
            '-c',
            'user.name=Test',
            '-c',
            'user.email=test@example.com',
            '-c',
            'commit.gpgsign=false',
            '-c',
            'tag.gpgsign=false',
            ...$arguments,
        );

        self::assertSame(0, $run->exitCode, $run->output);
    }

    private function checkApi(): ScriptRun
    {
        $script = \dirname(__DIR__, 2) . '/scripts/check-api.sh';

        return ScriptRun::of('sh', '-c', 'cd "$1" && exec "$2"', 'sh', $this->repo, $script);
    }
}
