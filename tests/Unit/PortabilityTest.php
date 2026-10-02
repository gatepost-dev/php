<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The package runs on shared WordPress hosts. Many of them lack ext-intl or ext-mbstring, and
 * on PHP 8.1 the case functions follow the locale. A Packagist install has no spec submodule,
 * so the package must read no files. A string such as 'strtoupper' is a callable, so a string
 * literal counts as a name too.
 */
final class PortabilityTest extends TestCase
{
    private const NAME_TOKENS = [
        \T_STRING,
        \T_NAME_QUALIFIED,
        \T_NAME_FULLY_QUALIFIED,
        \T_CONSTANT_ENCAPSED_STRING,
        \T_INCLUDE,
        \T_INCLUDE_ONCE,
        \T_REQUIRE,
        \T_REQUIRE_ONCE,
    ];

    /**
     * Names and name prefixes that src must not use, in lower case.
     *
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function bannedNames(): array
    {
        return [
            'functions from ext-mbstring (PHP-9)' => [[], ['mb_']],
            'classes and functions from ext-intl (PHP-9)' => [
                [
                    'collator', 'intlbreakiterator', 'intlchar', 'intldateformatter', 'locale',
                    'messageformatter', 'normalizer', 'numberformatter', 'resourcebundle',
                    'spoofchecker', 'transliterator', 'uconverter',
                ],
                [
                    'collator_', 'datefmt_', 'grapheme_', 'idn_', 'intl', 'locale_', 'msgfmt_',
                    'normalizer_', 'numfmt_', 'resourcebundle_', 'transliterator_',
                ],
            ],
            'case functions that follow the locale on PHP 8.1' => [
                ['lcfirst', 'strtolower', 'strtoupper', 'ucfirst', 'ucwords'],
                [],
            ],
            'file access, which a Packagist install cannot serve (CS-2)' => [
                [
                    'directoryiterator', 'file', 'file_exists', 'file_get_contents',
                    'file_put_contents', 'fopen', 'fread', 'fwrite', 'glob', 'include',
                    'include_once', 'is_file', 'opendir', 'readfile', 'require', 'require_once',
                    'scandir', 'splfileinfo', 'splfileobject',
                ],
                [],
            ],
            'the clock and randomness (CS-2)' => [
                [
                    'date', 'date_create', 'datetime', 'datetimeimmutable', 'gmdate', 'hrtime',
                    'microtime', 'mktime', 'sleep', 'strtotime', 'time', 'usleep',
                    'array_rand', 'lcg_value', 'mt_rand', 'rand', 'random_bytes', 'random_int',
                    'shuffle', 'str_shuffle', 'uniqid',
                ],
                [],
            ],
        ];
    }

    /**
     * @param list<string> $names
     * @param list<string> $prefixes
     */
    #[Test]
    #[DataProvider('bannedNames')]
    public function keepsTheSourceFreeOf(array $names, array $prefixes): void
    {
        $found = [];
        foreach (self::tokens('src') as [$place, $token]) {
            if (!\is_array($token) || !\in_array($token[0], self::NAME_TOKENS, true)) {
                continue;
            }
            // The quotes of a string literal are not part of the name.
            $name = \strtolower(\ltrim(\trim($token[1], '\'"'), '\\'));
            $banned = \in_array($name, $names, true);
            foreach ($prefixes as $prefix) {
                $banned = $banned || \str_starts_with($name, $prefix);
            }
            if ($banned) {
                $found[] = "{$name} in {$place}";
            }
        }

        self::assertSame([], $found);
    }

    #[Test]
    public function usesNoErrorSuppressionAndNoEval(): void
    {
        $found = [];
        foreach ([...self::tokens('src'), ...self::tokens('scripts')] as [$place, $token]) {
            if ($token === '@' || (\is_array($token) && $token[0] === \T_EVAL)) {
                $found[] = $place;
            }
        }

        self::assertSame([], $found, 'PHP-11 bans the @ operator and eval.');
    }

    /**
     * Each token in the PHP files of one folder, with its place as file name and line.
     *
     * @return list<array{string, array{int, string, int}|string}>
     */
    private static function tokens(string $folder): array
    {
        $tokens = [];
        $source = new RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/' . $folder);
        foreach (new RecursiveIteratorIterator($source) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = \file_get_contents($file->getPathname());
            self::assertIsString($code);
            $line = 1;
            foreach (\token_get_all($code) as $token) {
                $line = \is_array($token) ? $token[2] : $line;
                $tokens[] = ["{$file->getFilename()}:{$line}", $token];
            }
        }

        return $tokens;
    }
}
