<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in([__DIR__ . '/scripts', __DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__])
    // The generator writes these two files. Its --check mode keeps them in step.
    ->notPath(['Internal/NfkcTable.php', 'Internal/SpecData.php']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/build/php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        'declare_strict_types' => true,
        'final_class' => true,
        // On PHP 8.4, an unqualified call to a native function in a namespace compiles to two
        // branches, and Xdebug counts the one that tests cannot reach. T-7 measures branches.
        'native_function_invocation' => ['include' => ['@internal'], 'scope' => 'namespaced'],
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'strict_comparison' => true,
        'strict_param' => true,
    ]);
