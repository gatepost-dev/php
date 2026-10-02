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
 * so the package must read no files, and the core has no I/O, no clock and no randomness (CS-2).
 * A string such as 'strtoupper' is a callable, so a string literal counts as a name too.
 *
 * The check reads names from lists, because a tool that reads symbols leaves most of them out.
 * ComposerRequireChecker flags only a symbol of an extension that composer.json does not require.
 * File access, the environment, the clock and randomness sit in the core extensions that it
 * accepts, and it reads no string.
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

    // A name after one of these belongs to a class: a method, a property or a constant. It is not
    // a function of the global namespace, so a method named time() or file() is fine.
    private const MEMBER_OPERATORS = [
        \T_OBJECT_OPERATOR,
        \T_NULLSAFE_OBJECT_OPERATOR,
        \T_DOUBLE_COLON,
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
                    'chdir', 'chmod', 'copy', 'dir', 'directoryiterator', 'fclose', 'feof',
                    'fflush', 'fgetc', 'fgetcsv', 'fgets', 'file', 'file_exists',
                    'file_get_contents', 'file_put_contents', 'filemtime', 'filesize',
                    'filesystemiterator', 'flock', 'fopen', 'fpassthru', 'fputcsv', 'fputs',
                    'fread', 'fscanf', 'fseek', 'ftell', 'ftruncate', 'fwrite', 'getcwd', 'glob',
                    'globiterator', 'hash_file', 'include', 'include_once', 'is_dir', 'is_file',
                    'is_link', 'is_readable', 'is_writable', 'md5_file', 'mkdir', 'opendir',
                    'parse_ini_file', 'readdir', 'readfile', 'realpath',
                    'recursivedirectoryiterator', 'rename', 'require', 'require_once', 'rmdir',
                    'scandir', 'sha1_file', 'splfileinfo', 'splfileobject', 'spltempfileobject',
                    'stat', 'symlink', 'tempnam', 'tmpfile', 'touch', 'unlink',
                ],
                ['stream_'],
            ],
            'the environment, the network and other processes (CS-2)' => [
                [
                    'exec', 'fsockopen', 'getenv', 'mail', 'passthru', 'pfsockopen', 'popen',
                    'putenv', 'shell_exec', 'system',
                ],
                ['curl', 'gethostby', 'proc_', 'socket_'],
            ],
            'the clock and randomness (CS-2)' => [
                [
                    'date', 'date_create', 'date_create_immutable', 'date_default_timezone_get',
                    'date_default_timezone_set', 'datetime', 'datetimeimmutable', 'getdate',
                    'gettimeofday', 'gmdate', 'gmmktime', 'gmstrftime', 'hrtime', 'idate',
                    'localtime', 'microtime', 'mktime', 'sleep', 'strftime', 'strtotime',
                    'time', 'time_nanosleep', 'time_sleep_until', 'usleep',
                    'array_rand', 'lcg_value', 'mt_rand', 'mt_srand', 'openssl_random_pseudo_bytes',
                    'password_hash', 'rand', 'random_bytes', 'random_int', 'shuffle', 'srand',
                    'str_shuffle', 'uniqid',
                ],
                ['random\\'],
            ],
        ];
    }

    /**
     * Code that uses a banned name, and the names that the check must find in it. The code is
     * what a contributor could write in src. A name that only looks like a banned one must pass.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function plantedCode(): array
    {
        return [
            'a call of fgets' => ['fgets($handle);', ['fgets']],
            'a call of unlink' => ['\unlink($path);', ['unlink']],
            'a call of mkdir' => ['\mkdir($path);', ['mkdir']],
            'a call of getenv' => ['\getenv("HOME");', ['getenv']],
            'a call of curl_init' => ['\curl_init();', ['curl_init']],
            'a new DateTimeImmutable' => ['new \DateTimeImmutable();', ['datetimeimmutable']],
            'a call of exec' => ['\exec($command);', ['exec']],
            'a class of the random namespace' => [
                'new \Random\Randomizer();',
                ['random\randomizer'],
            ],
            'a require_once' => ["require_once 'data.php';", ['require_once']],
            'a function name as a string' => ['\array_map("strtoupper", $parts);', ['strtoupper']],
            'a static method of a banned class as a string' => [
                "\\call_user_func('Normalizer::normalize', \$text);",
                ['normalizer'],
            ],
            'the same with a backslash before the class' => [
                "\\call_user_func('\\Normalizer::normalize', \$text);",
                ['normalizer'],
            ],
            'methods and properties that share the name of a function' => [
                '$box->file(); $box->time; $box?->date(); Box::rand(); Box::DATE;',
                [],
            ],
            'text that mentions a banned name' => [
                "throw new \\LogicException('Cannot find the file at this time.');",
                [],
            ],
            'a comment that mentions a banned name' => [
                "// fgets() and unlink()\n/* mkdir() */",
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
        foreach (self::sourceFiles('src') as $file => $code) {
            foreach (self::bannedIn($code, $names, $prefixes) as [$name, $line]) {
                $found[] = "{$name} in {$file}:{$line}";
            }
        }

        self::assertSame([], $found);
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('plantedCode')]
    public function findsABannedNameInPlantedCode(string $statements, array $expected): void
    {
        $names = [];
        $prefixes = [];
        foreach (self::bannedNames() as [$groupNames, $groupPrefixes]) {
            $names = [...$names, ...$groupNames];
            $prefixes = [...$prefixes, ...$groupPrefixes];
        }

        $found = self::bannedIn("<?php {$statements}", $names, $prefixes);

        self::assertSame($expected, \array_column($found, 0));
    }

    #[Test]
    public function writesEachBannedNameInLowerCase(): void
    {
        $others = [];
        foreach (self::bannedNames() as [$names, $prefixes]) {
            foreach ([...$names, ...$prefixes] as $name) {
                if ($name !== \strtolower($name)) {
                    $others[] = $name;
                }
            }
        }

        self::assertSame([], $others, 'The check lowers the case of a name, so this entry fails.');
    }

    #[Test]
    public function usesNoErrorSuppressionAndNoEval(): void
    {
        $found = [];
        foreach ([...self::sourceFiles('src'), ...self::sourceFiles('scripts')] as $file => $code) {
            foreach (self::significantTokens($code) as [$id, , $line]) {
                if ($id === '@' || $id === \T_EVAL) {
                    $found[] = "{$file}:{$line}";
                }
            }
        }

        self::assertSame([], $found, 'PHP-11 bans the @ operator and eval.');
    }

    /**
     * The banned names that the code holds, each with its line.
     *
     * @param list<string> $names
     * @param list<string> $prefixes
     *
     * @return list<array{string, int}>
     */
    private static function bannedIn(string $code, array $names, array $prefixes): array
    {
        $tokens = self::significantTokens($code);
        $found = [];
        foreach ($tokens as $index => [$id, $text, $line]) {
            $isMember = \in_array($tokens[$index - 1][0] ?? null, self::MEMBER_OPERATORS, true);
            if ($isMember || !\in_array($id, self::NAME_TOKENS, true)) {
                continue;
            }
            foreach (self::spellings($text) as $name) {
                if (self::isBanned($name, $names, $prefixes)) {
                    $found[] = [$name, $line];
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The names that a token can mean, in lower case. A string literal can name a method as a
     * callable, such as 'Normalizer::normalize'. Then the name of the class counts too.
     *
     * @return list<string>
     */
    private static function spellings(string $text): array
    {
        // The quotes of a string literal are not part of the name.
        $name = \strtolower(\ltrim(\trim($text, '\'"'), '\\'));

        return [$name, \explode('::', $name)[0]];
    }

    /**
     * @param list<string> $names
     * @param list<string> $prefixes
     */
    private static function isBanned(string $name, array $names, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (\str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return \in_array($name, $names, true);
    }

    /**
     * Each token of the code, with its id or its character, its text and its line. White space
     * and comments are left out.
     *
     * @return list<array{int|string, string, int}>
     */
    private static function significantTokens(string $code): array
    {
        $tokens = [];
        $line = 1;
        foreach (\token_get_all($code) as $token) {
            $id = \is_array($token) ? $token[0] : $token;
            $line = \is_array($token) ? $token[2] : $line;
            if (!\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $tokens[] = [$id, \is_array($token) ? $token[1] : $token, $line];
            }
        }

        return $tokens;
    }

    /**
     * The code of each PHP file in one folder of the repo, by its path from the repo root.
     *
     * @return array<string, string>
     */
    private static function sourceFiles(string $folder): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];
        $source = new RecursiveDirectoryIterator("{$root}/{$folder}");
        foreach (new RecursiveIteratorIterator($source) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = \file_get_contents($file->getPathname());
            self::assertIsString($code);
            $files[\substr($file->getPathname(), \strlen($root) + 1)] = $code;
        }

        return $files;
    }
}
