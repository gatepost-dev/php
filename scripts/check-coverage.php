<?php

// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Fails when the branch coverage in a Cobertura report is below the floor that T-7 sets for
// core code. PHPUnit writes the report, but it has no coverage floor of its own.

namespace Gatepost\Postcode\Scripts;

const BRANCH_FLOOR_PERCENT = 95;

$report = $argv[1] ?? '';
$document = new \DOMDocument();
if (!\is_file($report) || !$document->load($report) || $document->documentElement === null) {
    \fwrite(\STDERR, "Name a Cobertura report, such as build/coverage/cobertura.xml.\n");
    exit(2);
}
$branches = (int) $document->documentElement->getAttribute('branches-valid');
$covered = (int) $document->documentElement->getAttribute('branches-covered');
if ($branches === 0) {
    \fwrite(\STDOUT, "The report has no branches, so there is nothing to cover.\n");
    exit(0);
}
$percent = 100 * $covered / $branches;
$summary = \sprintf('Branch coverage is %.2f %% (%d of %d).', $percent, $covered, $branches);
if ($percent < BRANCH_FLOOR_PERCENT) {
    \fwrite(\STDERR, $summary . ' The floor for core code is ' . BRANCH_FLOOR_PERCENT . " %.\n");
    exit(1);
}
\fwrite(\STDOUT, $summary . "\n");
