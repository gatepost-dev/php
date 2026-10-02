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
            // The check reads the address, so the first name of a person is no reason to refuse.
            'a co-author line for a person named Claude' => [
                self::message(
                    self::SUBJECT,
                    'Co-authored-by: Claude Monet <claude.monet@example.com>',
                    self::SIGN_OFF,
                ),
            ],
            'a co-author line for a person named Copilot' => [
                self::message(
                    self::SUBJECT,
                    'Co-authored-by: Copilot Jones <copilot.jones@example.com>',
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
            'a co-author line for Claude' => self::coAuthorRefused(
                'Co-Authored-By: Claude <noreply@anthropic.com>',
            ),
            'a co-author line with the address of Claude and another name' => self::coAuthorRefused(
                'Co-authored-by: Assistant <noreply@anthropic.com>',
            ),
            'a co-author line for Copilot' => self::coAuthorRefused(
                'Co-authored-by: Copilot <175728472+Copilot@users.noreply.github.com>',
            ),
            'a co-author line for Codex' => self::coAuthorRefused(
                'Co-authored-by: Codex <codex@openai.com>',
            ),
            'a co-author line at the domain of Anthropic' => self::coAuthorRefused(
                'Co-authored-by: Claude <claude@anthropic.com>',
            ),
            'a co-author line at the domain of OpenAI' => self::coAuthorRefused(
                'Co-authored-by: Assistant <assistant@openai.com>',
            ),
            'a co-author line for Copilot at github.com' => self::coAuthorRefused(
                'Co-authored-by: Copilot <copilot@github.com>',
            ),
            'a co-author line for Claude Code at GitHub' => self::coAuthorRefused(
                'Co-authored-by: Claude Code <claude-code@users.noreply.github.com>',
            ),
            'a co-author line for Gemini Code Assist' => self::coAuthorRefused(
                'Co-authored-by: Gemini <gemini-code-assist@google.com>',
            ),
            'a co-author line for Cursor' => self::coAuthorRefused(
                'Co-authored-by: Cursor Agent <cursoragent@cursor.com>',
            ),
            'a co-author line for Aider' => self::coAuthorRefused(
                'Co-authored-by: aider (gpt-4o) <noreply@aider.chat>',
            ),
            'a co-author line in lower case' => self::coAuthorRefused(
                'co-authored-by: claude <noreply@anthropic.com>',
            ),
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

    /**
     * @return array{string, string}
     */
    private static function coAuthorRefused(string $trailer): array
    {
        return [self::message(self::SUBJECT, $trailer, self::SIGN_OFF), 'Co-Authored-By'];
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
