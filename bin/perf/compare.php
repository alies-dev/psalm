<?php

declare(strict_types=1);

/**
 * Compare per-project perf results of a base and a head Psalm and write a Markdown report plus a
 * machine readable summary with the gate verdict.
 *
 * Usage: php compare.php --base <dir> --head <dir> --out-md <file> --out-json <file>
 *                        [--base-sha <sha>] [--head-sha <sha>] [--base-source cached|measured] [--run-url <url>]
 *
 * <dir> holds the flattened run-shard.sh output (<project>.json + <project>.issues.json).
 * An empty base dir is fine (head only report, gate cannot fail on deltas).
 * Exit: 0 on success (the verdict is in summary.json), 2 on usage error.
 *
 * Gate (fail): Ir > +3 %, Psalm MB (threads=1) > +5 %, or head crash/timeout where base was ok.
 * Ir/MB comparisons are invalid (shown, never gated) when base and head environments differ.
 */

require __DIR__ . '/lib/compare.php';

$o = getopt('', ['base:', 'head:', 'out-md:', 'out-json:', 'base-sha:', 'head-sha:', 'base-source:', 'run-url:']);
foreach (['base', 'head', 'out-md', 'out-json'] as $required) {
    if (!isset($o[$required]) || !is_string($o[$required]) || $o[$required] === '') {
        usage("missing --{$required}");
    }
}

foreach (['base', 'head'] as $side) {
    if (!is_dir($o[$side])) {
        usage("--{$side} is not a directory: {$o[$side]}");
    }
}

$baseSource = $o['base-source'] ?? 'measured';
if (!in_array($baseSource, ['cached', 'measured'], true)) {
    usage('--base-source must be cached or measured');
}

$baseResults = loadResults($o['base']);
$headResults = loadResults($o['head']);
$names = array_keys($baseResults + $headResults);
sort($names);

/** @var array<string, array<string, mixed>> $projects */
$projects = [];
foreach ($names as $name) {
    $projects[$name] = analyzeProject(
        $name,
        $baseResults[$name] ?? null,
        $headResults[$name] ?? null,
        $o['base'],
        $o['head'],
    );
}

$gateReasons = [];
foreach ($projects as $p) {
    array_push($gateReasons, ...$p['gate']);
}

if ($headResults === []) {
    $gateReasons[] = 'no head results were produced';
}

$gate = $gateReasons === [] ? 'pass' : 'fail';

// -- verdict
$shifted = 0;
$issueChanges = 0;
$problems = 0;
foreach ($projects as $name => $p) {
    $both = $p['status_base'] === 'ok' && $p['status_head'] === 'ok';
    if ($p['status_head'] !== 'ok' || ($p['status_base'] ?? 'ok') !== 'ok' || !$p['comparison_valid']) {
        $problems++;
    }

    if ($both && $p['comparison_valid']) {
        foreach (['ir', 'psalm_mb'] as $metric) {
            if (abs($p['metrics'][$metric]['delta_pct'] ?? 0.0) >= SHOW_PCT) {
                $shifted++;
                break;
            }
        }
    }

    if ($p['issues'] !== null && $p['issues']['added'] + $p['issues']['removed'] + $p['issues']['changed'] > 0) {
        $issueChanges++;
    }
}

if ($gate === 'fail') {
    $verdict = '❌ **Gate failed**: ' . implode('; ', $gateReasons);
} elseif ($shifted + $issueChanges + $problems > 0) {
    $notes = [];
    if ($shifted > 0) {
        $notes[] = "{$shifted} project(s) with an Ir/MB change of 0.5 % or more";
    }

    if ($issueChanges > 0) {
        $notes[] = "{$issueChanges} project(s) with issue changes";
    }

    if ($problems > 0) {
        $notes[] = "{$problems} project(s) not measured, crashed or not comparable";
    }

    $verdict = '⚠️ **Changes below the gate**: ' . implode(', ', $notes);
} elseif ($baseResults === []) {
    $verdict = '✅ **Head measured**, no base results to compare against';
} else {
    $verdict = '✅ **No significant change**';
}

// -- table
$md = $verdict . "\n\n";
if ($projects !== []) {
    $md .= "| Project | Ir Δ | Psalm MB Δ | wall (info) | issues base → head |\n|---|---|---|---|---|\n";
    foreach ($projects as $name => $p) {
        $md .= sprintf(
            "| %s | %s | %s | %s | %s |\n",
            $name,
            irCell($p, $headResults[$name] ?? null),
            mbCell($p),
            wallCell($p, $headResults[$name] ?? null),
            issuesCell($p, $baseResults[$name] ?? null, $headResults[$name] ?? null),
        );
    }

    $md .= "\nΔ below 0.5 % is shown as ≈. Gate: Ir > +" . (int) IR_GATE_PCT
        . ' %, Psalm MB (threads=1) > +' . (int) MB_GATE_PCT
        . " %, or a head crash/timeout where base was ok. Wall time is informational.\n";
}

foreach ($projects as $name => $p) {
    if (!$p['comparison_valid']) {
        $md .= sprintf(
            "\n> **%s**: Ir/MB comparison invalid, base and head environments differ (%s). Not gated.\n",
            $name,
            implode(', ', $p['invalid_reasons']),
        );
    }
}

// -- issue details, budgeted
$details = '';
$skipped = 0;
foreach ($projects as $name => $p) {
    if ($p['diff'] === null || $p['issues']['added'] + $p['issues']['removed'] + $p['issues']['changed'] === 0) {
        continue;
    }

    $block = renderDetails($name, $p);
    if (strlen($details) + strlen($block) > DETAILS_BUDGET_BYTES) {
        $skipped++;
        continue;
    }

    $details .= "\n" . $block;
}

if ($details !== '') {
    $md .= "\n### Issue changes (threads=1)\n" . $details;
}

if ($skipped > 0) {
    $md .= sprintf(
        "\n_%d more project(s) with issue changes omitted (details budget of %d bytes reached)._\n",
        $skipped,
        DETAILS_BUDGET_BYTES,
    );
}

// -- not run / crashed
$bucket = '';
foreach ($projects as $name => $p) {
    foreach (['base' => $baseResults, 'head' => $headResults] as $side => $results) {
        $r = $results[$name] ?? null;
        if ($r === null) {
            // A base result missing from head is a project the PR no longer measures,
            // or a base that was never produced.
            if ($side === 'head' || $baseResults !== []) {
                $bucket .= sprintf("- **%s** %s: no result\n", htmlspecialchars($name), $side);
            }

            continue;
        }

        if (($r['status'] ?? null) !== 'ok') {
            $bucket .= renderLogTail($name, $side, $r) . "\n";
        }
    }
}

if ($bucket !== '') {
    $md .= "\n### Not measured / crashed\n\n" . $bucket;
}

// -- footer
$anyEnv = ($headResults + $baseResults)[array_key_first($headResults + $baseResults) ?? ''] ['env'] ?? [];
$shortSha = static fn(?string $sha): string => $sha === null || $sha === '' ? 'n/a' : substr($sha, 0, 12);
$footer = sprintf(
    'base `%s` (%s), head `%s`, PHP %s',
    $shortSha($o['base-sha'] ?? null),
    $baseResults === [] ? 'none' : $baseSource,
    $shortSha($o['head-sha'] ?? null),
    $anyEnv['php'] ?? 'n/a',
);
if (!empty($anyEnv['valgrind'])) {
    $footer .= ', valgrind ' . $anyEnv['valgrind'];
}

if (isset($o['run-url']) && is_string($o['run-url']) && $o['run-url'] !== '') {
    $footer .= sprintf(', [workflow run](%s)', $o['run-url']);
}

$md .= "\n<sub>{$footer}</sub>\n";

// Psalm dependency versions that differ between base and head (a PR changing composer.json).
$basePackages = reset($baseResults)['env']['psalm_packages'] ?? [];
$headPackages = reset($headResults)['env']['psalm_packages'] ?? [];
$changedPackages = [];
foreach ($basePackages + $headPackages as $package => $_) {
    $before = $basePackages[$package] ?? null;
    $after = $headPackages[$package] ?? null;
    if ($before !== $after) {
        $changedPackages[] = sprintf('%s %s → %s', $package, $before ?? 'absent', $after ?? 'removed');
    }
}

if ($changedPackages !== []) {
    $md .= "\n<sub>Psalm dependencies differ: " . htmlspecialchars(implode(', ', array_slice($changedPackages, 0, 20)))
        . (count($changedPackages) > 20 ? sprintf(', … and %d more', count($changedPackages) - 20) : '') . "</sub>\n";
}

// -- summary.json
$summaryProjects = [];
foreach ($projects as $name => $p) {
    $summaryProjects[$name] = [
        'status_base' => $p['status_base'],
        'status_head' => $p['status_head'],
        'mode' => $p['mode'],
        'comparison_valid' => $p['comparison_valid'],
        'invalid_reasons' => $p['invalid_reasons'],
        'metrics' => $p['metrics'] === [] ? new stdClass() : $p['metrics'],
        'issues' => $p['issues'],
        'gate' => $p['gate'],
    ];
}

$summary = [
    'schema' => 1,
    'gate' => $gate,
    'reasons' => $gateReasons,
    'projects' => $summaryProjects === [] ? new stdClass() : $summaryProjects,
];

$summaryJson = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (file_put_contents($o['out-md'], $md) === false
    || file_put_contents($o['out-json'], $summaryJson . "\n") === false
) {
    fwrite(STDERR, "compare: cannot write output files\n");
    exit(1);
}

echo "gate: {$gate}\n";
