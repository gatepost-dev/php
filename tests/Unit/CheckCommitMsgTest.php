<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use Gatepost\Postcode\Tests\ScriptRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The commit-msg hook and CI both run scripts/check-commit-msg, so these tests run the script
 * on message files.
 */
final class CheckCommitMsgTest extends TestCase
{
    private const SUBJECT = 'chore(repo): set up the tools';
    private const SIGN_OFF = 'Signed-off-by: Ada Lovelace <ada@example.com>';

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedMessages(): array
    {
        return [
            'a subject, a body and a sign-off' => [self::message(self::SUBJECT, self::SIGN_OFF)],
            'a subject of 72 characters' => [
                self::message('chore(repo): ' . \str_repeat('a', 59), self::SIGN_OFF),
            ],
            'a co-author line for a person' => [
                self::message(
                    self::SUBJECT,
                    'Co-authored-by: Grace Hopper <grace@example.com>',
                    self::SIGN_OFF,
                ),
            ],
        ];
    }

    #[Test]
    #[DataProvider('acceptedMessages')]
    public function acceptsAMessage(string $message): void
    {
        $run = self::check($message);

        self::assertSame(0, $run->exitCode, $run->output);
        self::assertSame('', $run->output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedMessages(): array
    {
        return [
            'a subject with no type and scope' => [
                self::message('Set up the tools', self::SIGN_OFF),
                'Write the subject as type(scope)',
            ],
            'a subject of 73 characters' => [
                self::message('chore(repo): ' . \str_repeat('a', 60), self::SIGN_OFF),
                'The subject has 73 characters',
            ],
            'a message with no sign-off' => [self::message(self::SUBJECT), 'Signed-off-by'],
            'a sign-off with no email address' => [
                self::message(self::SUBJECT, 'Signed-off-by: Ada Lovelace'),
                'Signed-off-by',
            ],
            'a co-author line for Claude' => [
                self::message(
                    self::SUBJECT,
                    'Co-Authored-By: Claude <noreply@anthropic.com>',
                    self::SIGN_OFF,
                ),
                'Co-Authored-By',
            ],
            'a co-author line that names Anthropic' => [
                self::message(
                    self::SUBJECT,
                    'Co-authored-by: Assistant <noreply@anthropic.com>',
                    self::SIGN_OFF,
                ),
                'Co-Authored-By',
            ],
            'a co-author line for Copilot' => [
                self::message(
                    self::SUBJECT,
                    'Co-authored-by: Copilot <copilot@example.com>',
                    self::SIGN_OFF,
                ),
                'Co-Authored-By',
            ],
            'a co-author line that names OpenAI' => [
                self::message(
                    self::SUBJECT,
                    'Co-authored-by: Codex <codex@openai.com>',
                    self::SIGN_OFF,
                ),
                'Co-Authored-By',
            ],
            'a co-author line in lower case' => [
                self::message(
                    self::SUBJECT,
                    'co-authored-by: claude <claude@example.com>',
                    self::SIGN_OFF,
                ),
                'Co-Authored-By',
            ],
        ];
    }

    #[Test]
    #[DataProvider('rejectedMessages')]
    public function rejectsAMessage(string $message, string $reason): void
    {
        $run = self::check($message);

        self::assertSame(1, $run->exitCode);
        self::assertStringContainsString($reason, $run->output);
    }

    private static function message(string $subject, string ...$trailers): string
    {
        $lines = [$subject, '', 'The body gives the reason.', '', ...$trailers];

        return \implode("\n", $lines) . "\n";
    }

    private static function check(string $message): ScriptRun
    {
        $file = \tempnam(\sys_get_temp_dir(), 'gatepost-message-');
        self::assertIsString($file);
        \file_put_contents($file, $message);

        try {
            return ScriptRun::of('scripts/check-commit-msg', $file);
        } finally {
            \unlink($file);
        }
    }
}
