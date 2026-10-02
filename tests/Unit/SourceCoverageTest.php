<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * PHPUnit counts the branches of a file only when something loads it. A class that no test uses
 * would count its lines and none of its branches, and it would pass the floor of T-7. This test
 * loads every class of the PSR-4 map of src/, so that the floor sees each file.
 */
final class SourceCoverageTest extends TestCase
{
    private const SOURCE_NAMESPACE = 'Gatepost\\Postcode\\';

    #[Test]
    public function loadsEveryClassOfTheSourceTree(): void
    {
        $unloaded = [];
        foreach (self::classNames() as $name) {
            // Only the first call runs the autoloader, which includes the file once.
            $isLoaded = \class_exists($name)
                || \interface_exists($name, false)
                || \trait_exists($name, false);
            if (!$isLoaded) {
                $unloaded[] = $name;
            }
        }

        self::assertSame([], $unloaded);
    }

    /**
     * The class that the PSR-4 rule expects in each file of src/.
     *
     * @return list<string>
     */
    private static function classNames(): array
    {
        $source = \dirname(__DIR__, 2) . '/src';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        $names = [];
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = \substr($file->getPathname(), \strlen($source) + 1, -\strlen('.php'));
            $names[] = self::SOURCE_NAMESPACE . \str_replace(\DIRECTORY_SEPARATOR, '\\', $path);
        }

        return $names;
    }
}
