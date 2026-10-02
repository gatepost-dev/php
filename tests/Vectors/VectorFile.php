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
     * function, holds no cases or holds one case twice.
     *
     * @param string $file     The file name without .json, such as parse-segments.
     * @param string $function The function that the file must test, such as parse.
     *
     * @return array<string, array{VectorCase}> Each case, keyed by its id and description,
     *                                          for a PHPUnit data provider.
     */
    public static function cases(string $file, string $function): array
    {
        $path = \dirname(__DIR__, 2) . "/spec/vectors/{$file}.json";
        $text = \file_get_contents($path);
        if ($text === false) {
            throw new UnexpectedValueException("Cannot read {$path}. Check out the submodule.");
        }
        $vectors = \json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($vectors) || ($vectors['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new UnexpectedValueException("{$file}.json does not have format version 1.");
        }
        if (($vectors['function'] ?? null) !== $function) {
            throw new UnexpectedValueException("{$file}.json does not test {$function}.");
        }
        $cases = $vectors['cases'] ?? null;
        if (!\is_array($cases) || $cases === []) {
            throw new UnexpectedValueException("{$file}.json has no cases.");
        }
        $provided = [];
        foreach ($cases as $case) {
            $vector = VectorCase::fromJson($case, $file);
            $name = "{$vector->id} {$vector->description}";
            if (isset($provided[$name])) {
                throw new UnexpectedValueException("{$file}.json holds the case {$name} twice.");
            }
            $provided[$name] = [$vector];
        }

        return $provided;
    }
}
