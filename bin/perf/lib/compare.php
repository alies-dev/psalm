<?php

declare(strict_types=1);

const IR_REGRESSION_PCT = 3.0;
const MB_REGRESSION_PCT = 5.0;
const NEAR_LIMIT_RATIO = 0.8;
const DRIFT_RATIO = 1.2;
const OOM_RSS_RATIO = 0.9;
const DEFAULT_VM_RAM_KB = 16 * 1024 * 1024;
const METRICS = ['ir', 'psalm_mb', 'wall_t1', 'cpu_t1', 'rss_t1_kb', 'wall_t4', 'rss_t4_kb', 'psalm_mb_t4'];

function usage(string $message): never
{
    fwrite(STDERR, "compare: {$message}\n");
    fwrite(STDERR, "usage: php compare.php --expected <expected.json> --base <dir> --head <dir> [--bundle-dir <dir>] "
        . "--locks-info <json> --out-md <file> --out-short <file> --out-json <file> [--run-url <url>]\n");
    exit(2);
}

/**
 * @return array<string, mixed>|null
 */
function readJson(string $file): ?array
{
    $raw = is_file($file) ? file_get_contents($file) : false;
    $data = $raw === false ? null : json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * @param array<string, mixed> $exp
 * @param array<string, mixed>|null $lock
 * @return array{result: array<string, mixed>|null, problem: string|null, issues: list<array<string, mixed>>|null}
 */
function loadSide(?string $dir, string $project, string $rev, array $exp, ?array $lock): array
{
    $none = static fn(string $problem): array => ['result' => null, 'problem' => $problem, 'issues' => null];
    if ($dir === null) {
        return $none('result unavailable');
    }

    $file = rtrim($dir, '/') . "/{$project}.json";
    if (!is_file($file)) {
        return $none('result missing');
    }

    $r = readJson($file);
    if ($r === null) {
        return $none('result invalid (not a JSON object)');
    }

    $problems = ($r['project'] ?? null) === $project ? [] : ['project name differs'];
    $expectedRev = ['sha' => $exp['revs'][$rev]['sha'] ?? null, 'lock_sha256' => $lock['sha256'] ?? null];
    $problems = [
        ...$problems,
        ...perfResultProblems($r, $expectedRev, $exp, (string) ($exp['mode'] ?? ''), $dir),
    ];
    $issues = $problems === [] && $r['status'] === 'ok' ? perfLoadIssues($dir, $r['issues_file'] ?? null) : null;

    if ($problems !== []) {
        return $none('result invalid (' . implode('; ', $problems) . ')');
    }

    return ['result' => $r, 'problem' => null, 'issues' => $issues];
}

function deltaPct(float|int|null $base, float|int|null $head): ?float
{
    if ($base === null || $head === null || $base == 0) {
        return null;
    }

    return ($head - $base) / $base * 100;
}

/** @param array<string, mixed> $r */
function metricOf(array $r, string $metric): float|int|null
{
    $v = $r['metrics'][$metric] ?? null;

    return is_numeric($v) ? $v + 0 : null;
}

/** @param array<string, mixed> $r */
function cpuOf(array $r): ?string
{
    $cpu = $r['env']['cpu'] ?? $r['env']['soft']['cpu'] ?? null;

    return is_string($cpu) && $cpu !== '' ? $cpu : null;
}

/**
 * True only when the run recorded that its CPU model is one corpus.json has measured expected_seconds on.
 *
 * @param array<string, mixed> $r
 */
function cpuMeasured(array $r): bool
{
    return ($r['env']['cpu_measured'] ?? null) === true;
}

/**
 * Normalised CPU model for same-CPU comparisons; null when unknown.
 *
 * @param array<string, mixed> $r
 */
function comparableCpu(array $r): ?string
{
    $cpu = cpuOf($r);

    return $cpu === null || strtolower($cpu) === 'unknown' ? null : perfNormalizeCpu($cpu);
}

/**
 * Shared libraries (soft fingerprint) whose hash differs between the runs; empty when either side has no data.
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $head
 * @return list<string>
 */
function softLibDiff(array $base, array $head): array
{
    $b = $base['env']['soft']['libs'] ?? null;
    $h = $head['env']['soft']['libs'] ?? null;
    if (!is_array($b) || !is_array($h)) {
        return [];
    }

    $differs = [];
    foreach (array_unique([...array_keys($b), ...array_keys($h)]) as $path) {
        if (($b[$path] ?? null) !== ($h[$path] ?? null)) {
            $differs[] = basename((string) $path);
        }
    }

    sort($differs);

    return $differs;
}

/**
 * PHP "Allowed memory size" or "Hard memory limit" fatal, an OOM kill, or (only when the OOM counter is unknown) a
 * SIGKILL while the process held at least 90 % of the VM's RAM.
 *
 * @param array<string, mixed> $head
 */
function isMemoryCrash(array $head): bool
{
    $tail = (string) ($head['log_tail'] ?? '') . "\n" . (string) ($head['reason'] ?? '');
    if (preg_match('/Allowed memory size|Hard memory limit/', $tail) === 1) {
        return true;
    }

    if (is_bool($head['oom_killed'] ?? null)) {
        return $head['oom_killed'];
    }

    $killed = ($head['exit_code'] ?? null) === 137 || ($head['signal'] ?? null) === 'SIGKILL';
    $rssKb = $head['max_rss_kb'] ?? $head['metrics']['rss_t1_kb'] ?? null;
    $ramKb = $head['env']['vm_ram_kb'] ?? DEFAULT_VM_RAM_KB;

    return $killed && is_numeric($rssKb) && is_numeric($ramKb) && $rssKb >= OOM_RSS_RATIO * $ramKb;
}

/**
 * Base time scaled to the slowest measured CPU (cachegrind: instruction count; native: only the measured wall time).
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $exp
 */
function predictedSlowestSeconds(array $base, array $exp): ?float
{
    $ir = metricOf($base, 'ir');
    if ($exp['mode'] === 'cachegrind') {
        $expIr = (float) ($exp['expected_ir'] ?? 0);

        return $ir === null || $expIr <= 0 ? null : $ir * (float) ($exp['expected_seconds'] ?? 0) / $expIr;
    }

    $wall = metricOf($base, 'wall_t1');

    return $wall === null ? null : (float) $wall;
}

/**
 * @param array<string, mixed> $r ok result
 * @param array<string, mixed> $exp
 * @return list<string>
 */
function nearLimitMarks(string $side, array $r, array $exp): array
{
    $marks = [];
    $limit = (float) ($exp['limit_mb'] ?? 0);
    $mb = metricOf($r, 'psalm_mb');
    if ($limit > 0 && $mb !== null && $mb > NEAR_LIMIT_RATIO * $limit) {
        $marks[] = sprintf('%s near memory limit (%.0f of %.0f MB)', $side, $mb, $limit);
    }

    $timeout = (float) ($exp['timeout'] ?? 0);
    $wall = metricOf($r, 'wall_t1') ?? (is_numeric($r['elapsed_analysis'] ?? null) ? $r['elapsed_analysis'] : null);
    if ($timeout > 0 && $wall !== null && $wall >= NEAR_LIMIT_RATIO * $timeout) {
        $marks[] = sprintf('%s within 20 %% of timeout (%.0f of %.0f s)', $side, $wall, $timeout);
    }

    return $marks;
}

/**
 * @param array<string, mixed> $p
 * @param list<string> $reasons
 * @return array<string, mixed>
 */
function finish(array $p, string $outcome, array $reasons): array
{
    $p['outcome'] = $outcome;
    $p['reasons'] = $reasons;

    return $p;
}

/**
 * Head crashed or timed out where base was ok.
 *
 * @param array<string, mixed> $p
 * @param array<string, mixed> $exp
 * @return array<string, mixed>
 */
function headFailureOutcome(array $p, array $base, array $head, array $exp): array
{
    if ($head['status'] === 'crash') {
        if (!isMemoryCrash($head)) {
            return finish($p, 'regression', ['head crashed where base was ok']);
        }

        $limit = (float) ($exp['limit_mb'] ?? 0);
        $baseMb = (float) metricOf($base, 'psalm_mb');
        if ($limit <= 0 || $baseMb > NEAR_LIMIT_RATIO * $limit) {
            return finish($p, 'inconclusive', [sprintf(
                'head hit the memory limit but base (%.0f MB) is within 20 %% of it (%.0f MB)',
                $baseMb,
                $limit,
            )]);
        }

        return finish($p, 'regression', ['head hit the memory limit where base was ok']);
    }

    if (!cpuMeasured($head)) {
        return finish($p, 'inconclusive', ['head timed out on a CPU model whose speed is not calibrated']);
    }

    $timeout = (float) ($exp['timeout'] ?? 0);
    $predicted = predictedSlowestSeconds($base, $exp);
    if ($predicted === null || $timeout <= 0) {
        return finish($p, 'inconclusive', ['head timed out; base time cannot be scaled to the slowest CPU']);
    }

    if ($exp['mode'] === 'native') {
        $baseCpu = comparableCpu($base);
        if ($baseCpu === null || $baseCpu !== comparableCpu($head)) {
            return finish($p, 'inconclusive', ['head timed out; native base ran on another or an unknown CPU']);
        }
    }

    if ($predicted > NEAR_LIMIT_RATIO * $timeout) {
        return finish($p, 'inconclusive', [sprintf(
            'head timed out but base is within 20 %% of the timeout (predicted %.0f of %.0f s)',
            $predicted,
            $timeout,
        )]);
    }

    return finish($p, 'regression', [sprintf(
        'head timed out (%.0f s) where base needs ~%.0f s (scaled to the slowest CPU for cachegrind)',
        $timeout,
        $predicted,
    )]);
}

/**
 * Both ok, same env fingerprint.
 *
 * @param array<string, mixed> $p
 * @param array<string, mixed> $base
 * @param array<string, mixed> $head
 * @return array<string, mixed>
 */
function compareOutcome(array $p, array $base, array $head): array
{
    $reasons = [];
    $irDowngraded = false;
    foreach (['ir' => IR_REGRESSION_PCT, 'psalm_mb' => MB_REGRESSION_PCT] as $metric => $limit) {
        $m = $p['metrics'][$metric] ?? null;
        if ($m === null || $m['delta_pct'] === null || $m['delta_pct'] <= $limit) {
            continue;
        }

        if ($metric === 'ir' && $p['env_differs'] !== []) {
            $irDowngraded = true;
            continue;
        }

        $label = $metric === 'ir' ? 'Ir' : 'Psalm MB';
        $reasons[] = sprintf('%s %s (limit +%d %%)', $label, fmtPct($m['delta_pct']), (int) $limit);
    }

    if ($reasons !== []) {
        return finish($p, 'regression', $reasons);
    }

    if ($irDowngraded) {
        return finish($p, 'inconclusive', [sprintf(
            'Ir %s, but shared libraries differ (%s)',
            fmtPct($p['metrics']['ir']['delta_pct']),
            implode(', ', array_slice($p['env_differs'], 0, 5)),
        )]);
    }

    return finish($p, 'compared', []);
}

/**
 * @param array<string, mixed> $p
 * @param array<string, mixed> $head ok result
 * @param array<string, mixed> $exp
 * @return array<string, mixed>
 */
function headOnlyOutcome(array $p, array $head, array $exp): array
{
    $mb = metricOf($head, 'psalm_mb');
    if ($mb !== null && $mb > (float) ($exp['expected_mb'] ?? INF)) {
        $p['warnings'][] = sprintf(
            'Psalm MB %.0f exceeds expected_mb %.0f; update corpus.json',
            $mb,
            $exp['expected_mb'],
        );
    }

    $expSeconds = (float) ($exp['expected_seconds'] ?? 0);
    $predicted = predictedSlowestSeconds($head, $exp);
    if ($expSeconds > 0 && $predicted !== null && $predicted > DRIFT_RATIO * $expSeconds) {
        $p['warnings'][] = sprintf(
            'drift: predicted slowest-CPU time %.0f s > 1.2 x expected %.0f s',
            $predicted,
            $expSeconds,
        );
    }

    $p['near'] = nearLimitMarks('head', $head, $exp);
    foreach (METRICS as $metric) {
        if (metricOf($head, $metric) !== null) {
            $p['metrics'][$metric] = ['base' => null, 'head' => metricOf($head, $metric), 'delta_pct' => null];
        }
    }

    return finish($p, 'compared', []);
}

/**
 * Everything compare knows about one project.
 *
 * @param array<string, mixed> $exp project entry of expected.json
 * @param array{
 *     headOnly: bool,
 *     lockFailure: ?string,
 *     baseLockFailure: ?string,
 *     baseDir: ?string,
 *     headDir: ?string,
 *     bundleDir: ?string,
 *     locks: array<string, mixed>
 * } $ctx
 * @return array<string, mixed>
 */
function evaluateProject(string $name, array $exp, array $ctx): array
{
    $baseSource = (string) ($exp['revs']['base']['source'] ?? 'none');
    $p = [
        'outcome' => 'inconclusive',
        'reasons' => [],
        'warnings' => [],
        'near' => [],
        'mode' => $exp['mode'] ?? null,
        'base_source' => $baseSource,
        'status_base' => null,
        'status_head' => null,
        'base' => null,
        'head' => null,
        'metrics' => [],
        'env_differs' => [],
        'issues' => null,
        'diff' => null,
    ];

    $head = loadSide($ctx['headDir'], $name, 'head', $exp, $ctx['locks']['head'] ?? null);
    $p['head'] = $head['result'];
    $p['status_head'] = $head['result']['status'] ?? null;

    if ($ctx['lockFailure'] !== null) {
        return finish($p, 'inconclusive', [$ctx['lockFailure']]);
    }

    if ($ctx['headOnly']) {
        if ($head['result'] === null) {
            return finish($p, 'inconclusive', ["head {$head['problem']}"]);
        }

        if ($p['status_head'] !== 'ok') {
            // A valid record of a failed measurement is listed with its reason. crash/timeout of the measured
            // process stay not comparable; setup-failed/inconclusive (harness or infrastructure failure) warn.
            $reason = "head {$p['status_head']}: " . resultReason($head['result']);
            if (in_array($p['status_head'], ['setup-failed', 'inconclusive'], true)) {
                return finish($p, 'inconclusive', [$reason]);
            }

            return finish($p, 'not_comparable', [$reason]);
        }

        return headOnlyOutcome($p, $head['result'], $exp);
    }

    if ($ctx['baseLockFailure'] !== null) {
        return finish($p, 'inconclusive', [$ctx['baseLockFailure']]);
    }

    $baseDir = match ($baseSource) {
        'measured' => $ctx['baseDir'],
        'cached' => $ctx['bundleDir'] === null ? null : rtrim($ctx['bundleDir'], '/') . '/results',
        default => null,
    };
    $base = $baseSource === 'none'
        ? ['result' => null, 'problem' => 'result not expected', 'issues' => null]
        : loadSide($baseDir, $name, 'base', $exp, $ctx['locks']['base'] ?? null);
    $p['base'] = $base['result'];
    $p['status_base'] = $base['result']['status'] ?? null;

    if (in_array($p['status_base'], ['crash', 'timeout'], true)) {
        return finish($p, 'not_comparable', ["base {$p['status_base']}: " . resultReason($base['result'])]);
    }

    foreach (['base' => $base, 'head' => $head] as $side => $s) {
        if ($s['result'] === null) {
            return finish($p, 'inconclusive', ["{$side} {$s['problem']}"]);
        }

        if (in_array($s['result']['status'], ['setup-failed', 'inconclusive'], true)) {
            return finish($p, 'inconclusive', ["{$side} {$s['result']['status']}: " . resultReason($s['result'])]);
        }
    }

    return compareResults($p, $base, $head, $exp);
}

/**
 * Both sides are valid; base is ok, head is anything but setup-failed/inconclusive.
 *
 * @param array<string, mixed> $p
 * @param array{result: array<string, mixed>|null, issues: list<array<string, mixed>>|null} $base
 * @param array{result: array<string, mixed>|null, issues: list<array<string, mixed>>|null} $head
 * @param array<string, mixed> $exp
 * @return array<string, mixed>
 */
function compareResults(array $p, array $base, array $head, array $exp): array
{
    $b = $base['result'];
    $h = $head['result'];
    $p['near'] = nearLimitMarks('base', $b, $exp);
    $baseMb = metricOf($b, 'psalm_mb');
    if ($baseMb !== null && $baseMb > (float) ($exp['expected_mb'] ?? INF)) {
        $p['warnings'][] = sprintf(
            'base Psalm MB %.0f exceeds expected_mb %.0f; update corpus.json',
            $baseMb,
            $exp['expected_mb'],
        );
    }

    $baseFp = $b['env']['fingerprint'] ?? null;
    $headFp = $h['env']['fingerprint'] ?? null;
    $p['env_differs'] = softLibDiff($b, $h);

    if ($h['status'] !== 'ok') {
        if (!is_string($headFp) || $headFp === '') {
            return finish($p, 'inconclusive', ["head {$h['status']}, and its environment is unknown (no fingerprint)"]);
        }

        if ($baseFp !== $headFp) {
            return finish($p, 'inconclusive', ["head {$h['status']}, and the environment fingerprint differs"]);
        }

        return headFailureOutcome($p, $b, $h, $exp);
    }

    $p['near'] = [...$p['near'], ...nearLimitMarks('head', $h, $exp)];
    foreach (METRICS as $metric) {
        $bv = metricOf($b, $metric);
        $hv = metricOf($h, $metric);
        if ($bv !== null && $hv !== null) {
            $p['metrics'][$metric] = ['base' => $bv, 'head' => $hv, 'delta_pct' => deltaPct($bv, $hv)];
        }
    }

    if ($base['issues'] !== null && $head['issues'] !== null) {
        $diff = diffIssues($base['issues'], $head['issues']);
        $p['diff'] = $diff;
        $p['issues'] = [
            'base' => count($base['issues']),
            'head' => count($head['issues']),
            'added' => count($diff['added']),
            'removed' => count($diff['removed']),
            'changed' => count($diff['changed']),
            'by_type' => countByType($diff),
        ];
    }

    if ($baseFp !== $headFp) {
        return finish($p, 'inconclusive', ['environment fingerprint differs between base and head']);
    }

    return compareOutcome($p, $b, $h);
}

/** @param array<string, mixed> $r */
function resultReason(array $r): string
{
    $reason = trim((string) ($r['reason'] ?? ''));
    if ($reason !== '') {
        return $reason;
    }

    $lines = array_values(array_filter(
        array_map('trim', explode("\n", (string) ($r['log_tail'] ?? ''))),
        static fn(string $l): bool => $l !== '',
    ));
    $fatal = array_values(array_filter(
        $lines,
        static fn(string $l): bool => preg_match('/Fatal|Allowed memory|Hard memory limit/i', $l) === 1,
    ));

    return $fatal[0] ?? $lines[count($lines) - 1] ?? 'no details';
}
