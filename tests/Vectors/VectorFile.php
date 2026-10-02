<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Gatepost\Postcode\Tests\Vectors;

use UnexpectedValueException;

/**
 * Reads the vector files in the spec submodule.
 */
final class VectorFile
{
    private const FORMAT_VERSION = 1;

    /**
     * Reads one vector file. It fails when the file has another format version, tests another
     * function, holds no cases, uses one id twice or gives two cases one name.
     *
     * @param string $file     The file name without .json, such as parse-segments.
     * @param string $function The function that the file must test, such as parse.
     *
     * @return array<string, array{VectorCase}> Each case, keyed by its id and description,
     *                                          for a PHPUnit data provider.
     */
    public static function cases(string $file, string $function): array
    {
        $text = self::read(\dirname(__DIR__, 2) . "/spec/vectors/{$file}.json");
        $vectors = \json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($vectors) || ($vectors['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new UnexpectedValueException(
                "{$file}.json does not have format version " . self::FORMAT_VERSION . '.',
            );
        }
        if (($vectors['function'] ?? null) !== $function) {
            throw new UnexpectedValueException("{$file}.json does not test {$function}.");
        }
        $cases = $vectors['cases'] ?? null;
        if (!\is_array($cases) || $cases === []) {
            throw new UnexpectedValueException("{$file}.json has no cases.");
        }
        $ids = [];
        $provided = [];
        foreach ($cases as $case) {
            $vector = VectorCase::fromJson($case, $file);
            if (isset($ids[$vector->id])) {
                throw new UnexpectedValueException("{$file}.json uses the id {$vector->id} twice.");
            }
            $ids[$vector->id] = true;
            $name = "{$vector->id} {$vector->description}";
            // Two ids can give one name, such as "a b" with "c" and "a" with "b c". An array
            // key holds one value, so the second case would silently replace the first.
            if (isset($provided[$name])) {
                throw new UnexpectedValueException(
                    "{$file}.json gives two cases the name {$name}.",
                );
            }
            $provided[$name] = [$vector];
        }

        return $provided;
    }

    private static function read(string $path): string
    {
        // A missing file gives false here, so the clear message below replaces a PHP warning.
        $text = \is_file($path) ? \file_get_contents($path) : false;
        if ($text === false) {
            throw new UnexpectedValueException("Cannot read {$path}. Check out the submodule.");
        }

        return $text;
    }
}
