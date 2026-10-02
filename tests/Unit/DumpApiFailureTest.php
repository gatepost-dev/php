<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A dump with a wrong or a missing symbol would pass the check that compares it with itself. So
 * the script stops with a message, and exit code 2, when it cannot read the repo or cannot write
 * a value.
 */
final class DumpApiFailureTest extends DumpApiTestCase
{
    #[Test]
    public function rejectsAnUnknownArgument(): void
    {
        $run = $this->dump('--bogus');

        self::assertSame(2, $run->exitCode);
        self::assertSame('Unknown argument --bogus. Use --check or no argument.', $run->output);
    }

    #[Test]
    public function saysWhenTheRepoHasNoComposerJson(): void
    {
        \unlink("{$this->repo}/composer.json");

        $run = $this->dump();

        self::assertSame(2, $run->exitCode);
        self::assertSame('Cannot read composer.json.', $run->output);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unusableComposerFiles(): array
    {
        $prefix = self::FIXTURE_NAMESPACE . '\\';

        return [
            'no name' => [
                ['autoload' => ['psr-4' => [$prefix => 'src/']]],
                'composer.json needs a name.',
            ],
            'no PSR-4 map' => [
                ['name' => 'fixture/api'],
                'composer.json needs an autoload.psr-4 map.',
            ],
            'a prefix with two folders' => [
                ['name' => 'fixture/api', 'autoload' => ['psr-4' => [$prefix => ['src/', 'lib/']]]],
                'Each PSR-4 entry of composer.json must name one folder.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $composer
     */
    #[Test]
    #[DataProvider('unusableComposerFiles')]
    public function saysWhatAComposerJsonLacks(array $composer, string $message): void
    {
        \file_put_contents("{$this->repo}/composer.json", \json_encode($composer));

        $run = $this->dump();

        self::assertSame(2, $run->exitCode, $run->output);
        self::assertSame($message, $run->output);
    }

    #[Test]
    public function namesTheFileThatDeclaresNoSymbolOfItsName(): void
    {
        \file_put_contents(
            "{$this->repo}/src/Stray.php",
            "<?php\n\nnamespace " . self::FIXTURE_NAMESPACE . ";\n\nfinal class Other {}\n",
        );

        $run = $this->dump();

        self::assertSame(2, $run->exitCode, $run->output);
        self::assertSame(
            self::FIXTURE_NAMESPACE . '\\Stray is not a class, an interface or an enum. '
            . 'Check that the file name follows the class name.',
            $run->output,
        );
    }

    #[Test]
    public function saysWhichDefaultValueItCannotWrite(): void
    {
        $code = "<?php\n\nnamespace " . self::FIXTURE_NAMESPACE . ";\n\nfinal class Odd\n{\n"
            . "    public function make(object \$tool = new \\stdClass()): void {}\n}\n";
        \file_put_contents("{$this->repo}/src/Odd.php", $code);

        $run = $this->dump();

        self::assertSame(2, $run->exitCode, $run->output);
        $owner = self::FIXTURE_NAMESPACE . '\\Odd::make()';
        self::assertSame(
            "Cannot write the default value of \$tool in {$owner}. The script writes null, "
            . 'booleans, numbers, printable ASCII text, an empty array and enum cases, but the '
            . 'value has the type stdClass.',
            $run->output,
        );
    }

    #[Test]
    public function saysWhichConstantHoldsTextThatItCannotWrite(): void
    {
        $code = "<?php\n\nnamespace " . self::FIXTURE_NAMESPACE . ";\n\nfinal class Cafe\n{\n"
            . '    public const NAME = "caf\u{00E9}";' . "\n}\n";
        \file_put_contents("{$this->repo}/src/Cafe.php", $code);

        $run = $this->dump();

        self::assertSame(2, $run->exitCode, $run->output);
        self::assertSame(
            'Cannot write the value of the constant ' . self::FIXTURE_NAMESPACE . '\\Cafe::NAME. '
            . 'The script writes null, booleans, numbers, printable ASCII text, an empty array and '
            . 'enum cases, but the value has the type string with a character outside printable '
            . 'ASCII.',
            $run->output,
        );
    }
}
