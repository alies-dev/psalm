<?php

declare(strict_types=1);

/**
 * Perf CI toolbox: `plan` expands the corpus into workflow jobs, `normalize` turns a Psalm JSON report into a
 * comparable issue list, `compare` judges base against head and renders the PR report. See bin/perf/README.md.
 */

require __DIR__ . '/lib/cli.php';
require __DIR__ . '/lib/plan.php';
require __DIR__ . '/lib/normalize.php';
require __DIR__ . '/lib/compare.php';
require __DIR__ . '/lib/report.php';

$command = $argv[1] ?? '';
$handlers = ['plan' => 'runPlan', 'normalize' => 'runNormalize', 'compare' => 'runCompare'];
if (!isset($handlers[$command])) {
    fwrite(STDERR, USAGE);
    exit(2);
}

try {
    exit($handlers[$command](array_slice($argv, 2)));
} catch (RuntimeException $e) {
    fwrite(STDERR, "perf {$command}: {$e->getMessage()}\n");
    exit(1);
}
