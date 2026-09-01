<?php

/**
 * Enforces a coverage floor by reading clover.xml.
 *
 * PHPUnit has no built-in threshold flag, and pulling in a package just for this would violate the project's dependency policy. Parsing the report ourselves is twenty lines.
 *
 * Usage: php bin/check-coverage.php build/logs/clover.xml 100
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

$file = $argv[1] ?? 'build/logs/clover.xml';
$floor = (float) ($argv[2] ?? 100);

if (!is_file($file)) {
    fwrite(STDERR, "  FAIL coverage — report not found: {$file}\n");

    // The usual cause is not a missing run but a missing driver. PHPUnit
    // downgrades that to a warning and writes no report at all, so without
    // this hint the message above sends you looking in the wrong place.
    // tests/.config/phpunit.xml.dist asks for path coverage, and only Xdebug
    // can provide it — pcov collects lines only.
    if (!extension_loaded('xdebug')) {
        fwrite(STDERR, "       Xdebug is not loaded. Path coverage needs it; pcov cannot provide it.\n");
        fwrite(STDERR, "       Install Xdebug and run with XDEBUG_MODE=coverage, or use `composer test`\n");
        fwrite(STDERR, "       for the suite without the coverage floor.\n");
    }

    exit(1);
}

$document = new DOMDocument();
$loaded = $document->load($file, LIBXML_NONET);
$metrics = $loaded
    ? (new DOMXPath($document))->query('/coverage/project/metrics')->item(0)
    : null;

if (!$metrics instanceof DOMElement) {
    fwrite(STDERR, "  FAIL coverage — unreadable report\n");
    exit(1);
}

$statements = (int) $metrics->getAttribute('statements');
$covered = (int) $metrics->getAttribute('coveredstatements');
$branches = (int) $metrics->getAttribute('conditionals');
$coveredBranches = (int) $metrics->getAttribute('coveredconditionals');

// A report with nothing in it used to satisfy a 100% floor, because zero
// statements was treated as full coverage. An empty report means the run went
// wrong, not that everything is covered.
if ($statements === 0) {
    fwrite(STDERR, "  FAIL coverage — report contains no executable statements\n");
    exit(1);
}

$linePct = ($covered / $statements) * 100;

printf("  lines    %6.2f%% (%d/%d)\n", $linePct, $covered, $statements);

// Clover always writes the conditionals attribute, so zero is ambiguous: it
// means either that the code genuinely has no branches, or that the run
// collected line coverage only. Neither is 100% branch coverage, and printing
// it as such is how a silently degraded driver stays invisible. Say what was
// measured instead.
if ($branches > 0) {
    $branchPct = ($coveredBranches / $branches) * 100;
    printf("  branches %6.2f%% (%d/%d)\n", $branchPct, $coveredBranches, $branches);
} else {
    $branchPct = null;
    fwrite(STDOUT, "  branches      n/a (report contains no branch data — floor not applied)\n");
}

$failed = $linePct + 0.001 < $floor
    || ($branchPct !== null && $branchPct + 0.001 < $floor);

if ($failed) {
    fwrite(STDERR, sprintf("  FAIL coverage — floor is %.2f%%\n", $floor));
    exit(1);
}

fwrite(STDOUT, "  OK   coverage\n");
