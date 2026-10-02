<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Checks each locked package against DEP-4: it has a permissive licence. With no argument, the
// script reads composer.lock and the composer.lock of each project in tools/. Name lock files to
// read only those. It exits with 1 when a package breaks the policy, and with 2 when it cannot
// use a lock file, so a lock that is missing, broken or empty cannot pass.

namespace Gatepost\Postcode\Scripts;

use RuntimeException;

// The same list as the js repo, so that each SDK applies one policy. DEP-4 names MIT, BSD, ISC and
// Apache-2.0. 0BSD is a BSD licence, and the last three are permissive too.
const ALLOWED_LICENCES = [
    'MIT',
    'ISC',
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    '0BSD',
    'BlueOak-1.0.0',
    'CC0-1.0',
    'Python-2.0',
];

// Composer writes a dual licence as a list, and the user of the package picks one item. An older
// package writes "(A or B)" in one item. The pattern reads nothing else, so an item such as
// "(A and B)" fails and a person must look at it.
const LICENCE_ITEM = '/\A\(?([\w.+-]+(?: or [\w.+-]+)*)\)?\z/i';

/**
 * @param list<string> $licences The items of the license field of a package.
 */
function hasAllowedLicence(array $licences): bool
{
    foreach ($licences as $item) {
        if (\preg_match(LICENCE_ITEM, $item, $match) !== 1) {
            continue;
        }
        $alternatives = \array_filter(
            \explode(' ', $match[1]),
            static fn(string $word): bool => \strcasecmp($word, 'or') !== 0,
        );
        if (\array_intersect($alternatives, ALLOWED_LICENCES) !== []) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{name: string, version: string, licences: list<string>}
 */
function lockedPackage(mixed $package, string $label): array
{
    $isNamed = \is_array($package)
        && \is_string($package['name'] ?? null)
        && \is_string($package['version'] ?? null);
    if (!$isNamed) {
        throw new RuntimeException("{$label} holds a package that has no name or no version.");
    }
    $licences = \is_array($package['license'] ?? null) ? $package['license'] : [];

    return [
        'name' => $package['name'],
        'version' => $package['version'],
        'licences' => \array_values(\array_filter($licences, \is_string(...))),
    ];
}

// A lock file is a JSON object, but the decoded value is not checked here.
function readLock(string $label, string $path): mixed
{
    $json = \is_file($path) && \is_readable($path) ? \file_get_contents($path) : false;
    if ($json === false) {
        throw new RuntimeException("Cannot read {$label}.");
    }
    $lock = \json_decode($json, true);
    if ($lock === null && \json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("{$label} is not valid JSON: " . \json_last_error_msg() . '.');
    }

    return $lock;
}

/**
 * @return list<array{name: string, version: string, licences: list<string>}>
 */
function lockedPackages(string $label, string $path): array
{
    $lock = readLock($label, $path);
    $packages = [];
    foreach (['packages', 'packages-dev'] as $key) {
        $group = \is_array($lock) ? ($lock[$key] ?? null) : null;
        foreach (\is_array($group) ? $group : [] as $package) {
            $packages[] = lockedPackage($package, $label);
        }
    }
    if ($packages === []) {
        throw new RuntimeException("{$label} lists no package, so it proves nothing.");
    }

    return $packages;
}

/**
 * @return array<string, string> Each lock file of the repo, by its path from the root.
 */
function repoLocks(string $root): array
{
    $found = \glob("{$root}/tools/*/composer.lock");
    $toolLocks = $found === false ? [] : $found;
    $locks = ['composer.lock' => "{$root}/composer.lock"];
    foreach ($toolLocks as $path) {
        $locks[\substr($path, \strlen($root) + 1)] = $path;
    }

    return $locks;
}

/**
 * @param list<string> $arguments
 */
function checkLicences(string $root, array $arguments): int
{
    $locks = $arguments === [] ? repoLocks($root) : \array_combine($arguments, $arguments);
    $violations = [];
    $summaries = [];
    try {
        foreach ($locks as $label => $path) {
            $packages = lockedPackages($label, $path);
            foreach ($packages as $package) {
                if (!hasAllowedLicence($package['licences'])) {
                    $licence = $package['licences'] === []
                        ? 'no licence'
                        : 'the licence ' . \implode(' or ', $package['licences']);
                    $violations[] = "{$label}: {$package['name']} {$package['version']} has "
                        . "{$licence}.";
                }
            }
            $count = \count($packages);
            $noun = $count === 1 ? 'package' : 'packages';
            $summaries[] = "{$label}: {$count} {$noun}, each with an allowed licence.";
        }
    } catch (RuntimeException $problem) {
        \fwrite(\STDERR, $problem->getMessage() . "\n");

        return 2;
    }
    if ($violations === []) {
        \fwrite(\STDOUT, \implode("\n", $summaries) . "\n");

        return 0;
    }
    \fwrite(\STDERR, \implode("\n", $violations) . "\n");
    \fwrite(\STDERR, 'DEP-4 allows only these licences: ' . \implode(', ', ALLOWED_LICENCES) . '. '
        . "Replace the package, or change ALLOWED_LICENCES in this script after a review.\n");

    return 1;
}

exit(checkLicences(\dirname(__DIR__), \array_slice($argv, 1)));
