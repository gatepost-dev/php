<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A folder in the system's temporary folder, for a test that needs a copy of the repo or a small
 * repo of its own. The test removes the folder in tearDown(), so that a failed assertion leaves
 * nothing behind.
 */
final class TemporaryFolder
{
    /**
     * Makes an empty folder. Only the current user can enter it.
     *
     * @return string The path of the folder.
     */
    public static function create(string $prefix): string
    {
        $path = \tempnam(\sys_get_temp_dir(), $prefix);
        // tempnam() makes a file. The folder takes its unique name.
        if ($path === false || !\unlink($path) || !\mkdir($path, 0o700)) {
            throw new RuntimeException('Cannot make a temporary folder.');
        }

        return $path;
    }

    /**
     * Removes the folder and everything in it.
     */
    public static function remove(string $path): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }
        \rmdir($path);
    }
}
