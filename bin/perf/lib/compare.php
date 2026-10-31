<?php

declare(strict_types=1);

const MB_FAIL_PCT = 5.0;
const WALL_WARN_PCT = 20.0;
/** A crashed head is only a regression when the base ran comfortably below the limit it hit. */
const LIMIT_HEADROOM = 0.5;
const EXAMPLES_PER_LIST = 10;
const MESSAGE_CHARS = 300;
const STATUSES = ['ok', 'crash', 'timeout', 'error'];
const VERDICT_RANK = ['pass' => 0, 'skip' => 0, 'warn' => 1, 'fail' => 2];

/**
 * Judges every project of the plan.
 *
 * @param array<mixed> $plan
 * @return array{verdict: string, deps_changed: bool, projects: array<string, array<string, mixed>>}
 */
function compareAll(array $plan, string $inDir, bool $depsChanged): array
{
    $projects = [];
    foreach ($plan['jobs'] as $job) {
        foreach ($job['projects'] as $name) {
            $dir = "{$inDir}/perf-{$job['job']}";
            $base = loadResult("{$dir}/base/{$name}.json");
            $head = loadResult("{$dir}/head/{$name}.json");
            $judged = judgeProject($base, $head, $plan, $name, $depsChanged);
            if ($base['status'] === 'ok' && $head['status'] === 'ok') {
                $judged = withIssueDiff($judged, $dir, $name);
            }

            $projects[$name] = ['job' => $job['job'], 'base' => slim($base), 'head' => slim($head)] + $judged;
        }
    }

    $worst = max(array_map(static fn(array $p): int => VERDICT_RANK[$p['verdict']], $projects) ?: [0]);

    return [
        'verdict' => ['pass', 'warn', 'fail'][$worst],
        'deps_changed' => $depsChanged,
        'projects' => $projects,
    ];
}

/**
 * A usable result, or a stand-in with status 'missing' and the reason.
 *
 * @return array<string, mixed>
 */
function loadResult(string $file): array
{
    try {
        $r = readJsonFile($file);
    } catch (RuntimeException $e) {
        return ['status' => 'missing', 'reason' => is_file($file) ? $e->getMessage() : 'no result file'];
    }

    if (($r['schema'] ?? null) !== 1 || !in_array($r['status'] ?? null, STATUSES, true)) {
        return ['status' => 'missing', 'reason' => 'invalid result (schema or status)'];
    }

    if ($r['status'] === 'ok' && !(is_numeric($r['psalm_mb'] ?? null) && is_numeric($r['wall'] ?? null))) {
        return ['status' => 'missing', 'reason' => 'invalid result (ok without psalm_mb or wall)'];
    }

    return $r;
}

/**
 * @param array<string, mixed> $r
 * @return array<string, mixed>
 */
function slim(array $r): array
{
    $keys = ['status', 'reason', 'memory_crash', 'psalm_mb', 'wall', 'cpu', 'exit_code', 'issue_count', 'log_tail'];

    return array_intersect_key($r, array_flip($keys)) + ['runs' => count($r['runs'] ?? [])];
}

/**
 * @param array<string, mixed> $base
 * @param array<string, mixed> $head
 * @param array<mixed> $plan
 * @return array{verdict: string, notes: list<string>, mb_delta: ?float, wall_delta: ?float}
 */
function judgeProject(array $base, array $head, array $plan, string $name, bool $depsChanged): array
{
    $verdict = static fn(string $v, string ...$notes): array => [
        'verdict' => $v,
        'notes' => $notes,
        'mb_delta' => null,
        'wall_delta' => null,
    ];
    foreach (['base' => $base, 'head' => $head] as $side => $r) {
        if (in_array($r['status'], ['missing', 'error'], true)) {
            return $verdict('warn', "{$side}: " . ($r['reason'] ?? $r['status']));
        }
    }

    if ($base['status'] !== 'ok') {
        return $verdict('skip', "base {$base['status']}: not comparable");
    }

    if ($head['status'] !== 'ok') {
        return $verdict(...headFailure($base, $head, $plan, $name));
    }

    return judgeMetrics($base, $head, $depsChanged);
}

/**
 * @param array<string, mixed> $base ok
 * @param array<string, mixed> $head crash or timeout
 * @param array<mixed> $plan
 * @return array{string, string}
 */
function headFailure(array $base, array $head, array $plan, string $name): array
{
    if ($head['status'] === 'timeout') {
        $limit = (float) $plan['timeouts'][$name];
        $baseSize = (float) $base['wall'];
        $what = 'timed out (limit %.0fs), base took %.0fs';
    } elseif ($head['memory_crash'] ?? false) {
        $limit = (float) $plan['memory_limit_mb'];
        $baseSize = (float) $base['psalm_mb'];
        $what = 'ran out of memory (limit %.0f MB), base used %.0f MB';
    } else {
        return ['fail', 'head crashed (' . ($head['reason'] ?? 'exit ' . ($head['exit_code'] ?? '?')) . ')'];
    }

    $note = 'head ' . sprintf($what, $limit, $baseSize);
    $comfortable = $baseSize < LIMIT_HEADROOM * $limit;

    return [$comfortable ? 'fail' : 'warn', $note . ($comfortable ? '' : ', already near the limit')];
}

/**
 * @param array<string, mixed> $base ok
 * @param array<string, mixed> $head ok
 * @return array{verdict: string, notes: list<string>, mb_delta: ?float, wall_delta: ?float}
 */
function judgeMetrics(array $base, array $head, bool $depsChanged): array
{
    $mb = pctDelta((float) $base['psalm_mb'], (float) $head['psalm_mb']);
    $wall = pctDelta((float) $base['wall'], (float) $head['wall']);
    $result = ['verdict' => 'pass', 'notes' => [], 'mb_delta' => $mb, 'wall_delta' => $wall];
    if ($depsChanged) {
        $result['notes'][] = 'composer.json changed: deltas are informational';

        return $result;
    }

    if ($mb !== null && $mb > MB_FAIL_PCT) {
        $result['verdict'] = 'fail';
        $result['notes'][] = sprintf('Psalm memory %+.1f %% (limit +%.0f %%)', $mb, MB_FAIL_PCT);
    } elseif ($wall !== null && $wall > WALL_WARN_PCT) {
        $result['verdict'] = 'warn';
        $result['notes'][] = sprintf('wall time %+.1f %% (limit +%.0f %%)', $wall, WALL_WARN_PCT);
    }

    return $result;
}

function pctDelta(float $base, float $head): ?float
{
    return $base > 0.0 ? ($head - $base) / $base * 100.0 : null;
}

/**
 * Adds the issue multiset diff of a project whose both sides are ok; unreadable lists make it a warning.
 *
 * @param array{verdict: string, notes: list<string>, mb_delta: ?float, wall_delta: ?float} $judged
 * @return array<string, mixed>
 */
function withIssueDiff(array $judged, string $dir, string $name): array
{
    try {
        $base = readJsonFile("{$dir}/base/{$name}.issues.json");
        $head = readJsonFile("{$dir}/head/{$name}.issues.json");
    } catch (RuntimeException $e) {
        $judged['verdict'] = $judged['verdict'] === 'fail' ? 'fail' : 'warn';
        $judged['notes'][] = 'issue lists unavailable: ' . $e->getMessage();

        return $judged + ['issues' => null];
    }

    return $judged + ['issues' => diffIssues($base, $head)];
}

/**
 * Issue identity: file, line_from, column_from, type, severity, message.
 *
 * @param list<array<string, mixed>> $issues
 * @return array<string, int>
 */
function tallyIssues(array $issues): array
{
    $counts = [];
    foreach ($issues as $i) {
        $key = implode("\0", [$i['file'] ?? '', $i['line_from'] ?? 0, $i['column_from'] ?? 0, $i['type'] ?? '?',
            $i['severity'] ?? '', $i['message'] ?? '']);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    return $counts;
}

/**
 * One entry per occurrence of every key that is more frequent in $from than in $against, sorted.
 *
 * @param array<string, int> $from
 * @param array<string, int> $against
 * @return list<list<string>> exploded keys
 */
function surplus(array $from, array $against): array
{
    $keys = [];
    foreach ($from as $key => $n) {
        array_push($keys, ...array_fill(0, max(0, $n - ($against[$key] ?? 0)), $key));
    }

    sort($keys);

    return array_map(static fn(string $k): array => explode("\0", $k), $keys);
}

/**
 * @param list<array<string, mixed>> $base
 * @param list<array<string, mixed>> $head
 * @return array{
 *     base: int, head: int, added: int, removed: int,
 *     types: array<string, array{added: int, removed: int}>,
 *     added_examples: list<string>, removed_examples: list<string>
 * }
 */
function diffIssues(array $base, array $head): array
{
    $baseTally = tallyIssues($base);
    $headTally = tallyIssues($head);
    $added = surplus($headTally, $baseTally);
    $removed = surplus($baseTally, $headTally);
    $types = [];
    foreach (['added' => $added, 'removed' => $removed] as $kind => $list) {
        foreach ($list as $parts) {
            $types[$parts[3]] ??= ['added' => 0, 'removed' => 0];
            $types[$parts[3]][$kind]++;
        }
    }

    ksort($types);
    $example = static fn(array $p): string => mb_strimwidth(
        "{$p[0]}:{$p[1]} {$p[3]} ({$p[4]}): " . preg_replace('/\s+/u', ' ', $p[5]),
        0,
        MESSAGE_CHARS,
        '…',
    );

    return [
        'base' => count($base),
        'head' => count($head),
        'added' => count($added),
        'removed' => count($removed),
        'types' => $types,
        'added_examples' => array_map($example, array_slice($added, 0, EXAMPLES_PER_LIST)),
        'removed_examples' => array_map($example, array_slice($removed, 0, EXAMPLES_PER_LIST)),
    ];
}
