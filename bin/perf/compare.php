<?php

declare(strict_types=1);

/**
 * Compare per-project perf results of a base and a head Psalm against what select.php expected, and write
 * report.md, report-short.md and summary.json (schema 2).
 *
 * Usage: php compare.php --expected <expected.json> --base <dir> --head <dir> [--bundle-dir <dir>]
 *            --locks-info <json> --out-md <f> --out-short <f> --out-json <f> [--run-url <url>]
 *
 * Outcome per project: regression, compared, not comparable (base crash/timeout), inconclusive.
 * Verdict: any regression => fail > any inconclusive => inconclusive > pass. Push/dispatch runs have no base:
 * the report lists the head results and is a pass unless a result is missing or invalid (a valid head crash,
 * timeout, setup-failed or inconclusive record is listed as not comparable with its reason, not a warning).
 * A failed base lock (pull_request) makes every project inconclusive: "dependency resolution failed for base: ...".
 * Result validation (sha, lock, mode, shard, status fields, issues file) lives in lib/validate.php.
 * Exit: 0 on success (the verdict is in summary.json), 2 on usage errors.
 */

require __DIR__ . '/lib/corpus.php';
require __DIR__ . '/lib/validate.php';
require __DIR__ . '/lib/compare.php';
require __DIR__ . '/lib/issues.php';
require __DIR__ . '/lib/report.php';

$o = getopt(
    '',
    ['expected:', 'base:', 'head:', 'bundle-dir:', 'locks-info:', 'out-md:', 'out-short:', 'out-json:', 'run-url:'],
);
foreach (['expected', 'head', 'locks-info', 'out-md', 'out-short', 'out-json'] as $required) {
    if (!isset($o[$required]) || !is_string($o[$required]) || $o[$required] === '') {
        usage("--{$required} is required");
    }
}

$stringOpt = static fn(string $key): ?string => isset($o[$key]) && is_string($o[$key]) && $o[$key] !== ''
    ? $o[$key]
    : null;

$expected = readJson($o['expected']);
if ($expected === null || !is_array($expected['projects'] ?? null)) {
    usage("{$o['expected']} is not a valid expected.json");
}

$locksInfo = readJson($o['locks-info']) ?? [];
$headOnly = ($expected['event'] ?? '') !== 'pull_request';
$lockFailed = ($locksInfo['head']['lock_source'] ?? $expected['locks']['head']['source'] ?? '') === 'failed';
$baseLockFailed = !$headOnly
    && ($locksInfo['base']['lock_source'] ?? $expected['locks']['base']['source'] ?? '') === 'failed';
$ctx = [
    'headOnly' => $headOnly,
    'lockFailure' => $lockFailed
        ? 'dependency resolution failed for head: ' . ($locksInfo['head']['reason'] ?? 'no reason recorded')
        : null,
    'baseLockFailure' => $baseLockFailed
        ? 'dependency resolution failed for base: ' . ($locksInfo['base']['reason'] ?? 'no reason recorded')
        : null,
    'baseDir' => $stringOpt('base'),
    'headDir' => $o['head'],
    'bundleDir' => $stringOpt('bundle-dir'),
    'locks' => $expected['locks'] ?? [],
];

$projects = [];
$expectedProjects = $expected['projects'];
ksort($expectedProjects);
foreach ($expectedProjects as $name => $exp) {
    $projects[$name] = evaluateProject((string) $name, $exp, $ctx);
}

$counts = countOutcomes($projects);
$verdict = verdictOf($counts);
$reasons = verdictReasons($projects);
if ($projects === []) {
    $verdict = 'inconclusive';
    $reasons[] = 'expected.json lists no projects';
}

$headLine = renderHeaderLine($verdict, $headOnly, (string) ($expected['head_sha'] ?? ''), $counts);
$table = renderTable($projects, $headOnly);
$md = $headLine . "\n\n" . $table
    . renderDetails($projects)
    . "\n" . renderFooter($expected, $locksInfo, $projects, $headOnly, $stringOpt('run-url'));
$short = $headLine . "\n\n" . $table;

$summary = json_encode(
    buildSummary($expected, $projects, $counts, $verdict, $reasons),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);
$written = $summary !== false
    && file_put_contents($o['out-md'], $md) !== false
    && file_put_contents($o['out-short'], $short) !== false
    && file_put_contents($o['out-json'], $summary . "\n") !== false;
if (!$written) {
    fwrite(STDERR, "compare: cannot write the report files\n");
    exit(1);
}

echo "verdict: {$verdict}\n";
