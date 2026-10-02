<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests;

/**
 * One run of a command in the repo root. The scripts in scripts/ end with an exit code, so the
 * tests that check them start each script as a command and read the code.
 */
final class ScriptRun
{
    private function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {}

    /**
     * Runs the command and waits for it. The output joins stdout and stderr.
     */
    public static function of(string ...$command): self
    {
        $arguments = \implode(' ', \array_map(\escapeshellarg(...), $command));
        $commandLine = 'cd ' . \escapeshellarg(\dirname(__DIR__)) . ' && ' . $arguments . ' 2>&1';
        \exec($commandLine, $lines, $exitCode);

        return new self($exitCode, \implode("\n", $lines));
    }
}
