<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Fails when the branch coverage in a Cobertura report is below the floor that T-7 sets for
// core code. PHPUnit writes the report, but it has no coverage floor of its own. PHPUnit also
// counts the lines of a class that no test loads, and none of its branches, so a report with
// lines and no branch data fails, and so does a class that has branches and no covered line.

namespace Gatepost\Postcode\Scripts;

const BRANCH_FLOOR_PERCENT = 95;

/**
 * The names of the classes that have branches and no covered line.
 *
 * @return list<string>
 */
function unrunClasses(\DOMDocument $report): array
{
    $names = [];
    foreach ($report->getElementsByTagName('class') as $class) {
        $hasCoveredLine = (float) $class->getAttribute('line-rate') > 0.0;
        if (!$hasCoveredLine && (int) $class->getAttribute('complexity') > 0) {
            $names[] = $class->getAttribute('name');
        }
    }

    return $names;
}

$report = $argv[1] ?? '';
$document = new \DOMDocument();
$isReadable = \is_file($report) && \is_readable($report);
if (!$isReadable || !$document->load($report) || $document->documentElement === null) {
    \fwrite(\STDERR, "Name a Cobertura report, such as build/coverage/cobertura.xml.\n");
    exit(2);
}
$lines = (int) $document->documentElement->getAttribute('lines-valid');
$branches = (int) $document->documentElement->getAttribute('branches-valid');
$covered = (int) $document->documentElement->getAttribute('branches-covered');
if ($branches === 0 && $lines === 0) {
    \fwrite(\STDOUT, "The report has no branches, so there is nothing to cover.\n");
    exit(0);
}
if ($branches === 0) {
    \fwrite(\STDERR, "The report has lines and no branch data. Add --path-coverage to PHPUnit.\n");
    exit(1);
}
$unrun = unrunClasses($document);
if ($unrun !== []) {
    $classes = \implode(', ', $unrun);
    \fwrite(\STDERR, "No test runs these classes: {$classes}. Add a test for each class.\n");
    exit(1);
}
$percent = 100 * $covered / $branches;
$summary = \sprintf('Branch coverage is %.2f %% (%d of %d).', $percent, $covered, $branches);
if ($percent < BRANCH_FLOOR_PERCENT) {
    \fwrite(\STDERR, $summary . ' The floor for core code is ' . BRANCH_FLOOR_PERCENT . " %.\n");
    exit(1);
}
\fwrite(\STDOUT, $summary . "\n");
