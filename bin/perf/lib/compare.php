<?php

declare(strict_types=1);

const IR_GATE_PCT = 3.0;
const MB_GATE_PCT = 5.0;
const SHOW_PCT = 0.5;
const LIST_CAP = 10;
const DETAILS_BUDGET_BYTES = 40_000;
const CLIP_CHARS = 300;
const LOG_TAIL_LINES = 15;
/**
 * env keys that must match for Ir/MB to be comparable. Absent on purpose: cpu (Ir is CPU independent), psalm_sha, and
 * psalm_deps/psalm_packages (base and head share one composer.lock, so a difference is caused by the PR and is gated).
 */
const FINGERPRINT_KEYS = ['php', 'valgrind', 'harness'];
const METRICS = ['ir', 'psalm_mb', 'wall_t1', 'cpu_t1', 'rss_t1_kb', 'wall_t4', 'rss_t4_kb', 'psalm_mb_t4'];

function usage(string $message): never
{
    fwrite(STDERR, "compare: {$message}\n");
    fwrite(STDERR, "usage: php compare.php --base <dir> --head <dir> --out-md <file> --out-json <file> "
        . "[--base-sha <sha>] [--head-sha <sha>] [--base-source cached|measured] [--run-url <url>]\n");
    exit(2);
}

/**
 * @return array<string, array<string, mixed>> project name => result
 */
function loadResults(string $dir): array
{
    $results = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
        if (str_ends_with($file, '.issues.json')) {
            continue;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && isset($data['project']) && is_string($data['project'])) {
            $results[$data['project']] = $data;
        }
    }

    ksort($results);

    return $results;
}

/**
 * @param array<string, mixed> $result
 * @return list<array<string, mixed>>|null
 */
function loadIssues(string $dir, array $result): ?array
{
    $name = is_string($result['issues_file'] ?? null) ? basename($result['issues_file']) : '';
    $file = rtrim($dir, '/') . '/' . $name;
    if ($name === '' || !is_file($file)) {
        return null;
    }

    $issues = json_decode((string) file_get_contents($file), true);

    return is_array($issues) && array_is_list($issues) ? $issues : null;
}

/** @param array<string, mixed> $i */
function issueKey(array $i): string
{
    return implode("\0", [
        (string) ($i['file'] ?? ''),
        (string) ($i['line_from'] ?? 0),
        (string) ($i['line_to'] ?? 0),
        (string) ($i['type'] ?? '?'),
        (string) ($i['message'] ?? ''),
        (string) ($i['column_from'] ?? 0),
        (string) ($i['column_to'] ?? 0),
    ]);
}

/**
 * @param list<array<string, mixed>> $issues
 * @return array<string, int>
 */
function tally(array $issues): array
{
    $counts = [];
    foreach ($issues as $issue) {
        $key = issueKey($issue);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    return $counts;
}

/**
 * Keys more frequent in $from than in $against, with the surplus.
 *
 * @param array<string, int> $from
 * @param array<string, int> $against
 * @return array<string, int>
 */
function surplus(array $from, array $against): array
{
    $diff = [];
    foreach ($from as $key => $n) {
        if ($n > ($against[$key] ?? 0)) {
            $diff[$key] = $n - ($against[$key] ?? 0);
        }
    }

    return $diff;
}

/**
 * One entry per key occurrence, sorted by file, line, type, message.
 *
 * @param array<string, int> $counts
 * @return list<array{type: string, loc: string, message: string}>
 */
function entries(array $counts): array
{
    $list = [];
    foreach ($counts as $key => $n) {
        $p = explode("\0", $key);
        for ($k = 0; $k < $n; $k++) {
            $list[] = [$p[0], (int) $p[1], $p[3], $p[4]];
        }
    }

    sort($list);

    return array_map(
        static fn(array $e): array => ['type' => $e[2], 'loc' => "{$e[0]}:{$e[1]}", 'message' => $e[3]],
        $list,
    );
}

/**
 * Multiset diff of two issue lists. Leftovers at the same file, line_from and type but with a different
 * message are paired up as "changed".
 *
 * @param list<array<string, mixed>> $base
 * @param list<array<string, mixed>> $head
 * @return array{
 *     added: list<array{type: string, loc: string, message: string}>,
 *     removed: list<array{type: string, loc: string, message: string}>,
 *     changed: list<array{type: string, loc: string, old: string, new: string}>
 * }
 */
function diffIssues(array $base, array $head): array
{
    $baseCounts = tally($base);
    $headCounts = tally($head);
    $removed = surplus($baseCounts, $headCounts);
    $added = surplus($headCounts, $baseCounts);
    ksort($removed);
    ksort($added);

    $group = static fn(array $p): string => "{$p[0]}\0{$p[1]}\0{$p[3]}";
    $addedByGroup = [];
    foreach (array_keys($added) as $key) {
        $addedByGroup[$group(explode("\0", $key))][] = $key;
    }

    $changed = [];
    foreach (array_keys($removed) as $oldKey) {
        $o = explode("\0", $oldKey);
        foreach ($addedByGroup[$group($o)] ?? [] as $newKey) {
            $n = explode("\0", $newKey);
            while ($removed[$oldKey] > 0 && $added[$newKey] > 0) {
                $changed[] = ['type' => $o[3], 'loc' => "{$o[0]}:{$o[1]}", 'old' => $o[4], 'new' => $n[4]];
                $removed[$oldKey]--;
                $added[$newKey]--;
            }
        }
    }

    return [
        'added' => entries(array_filter($added)),
        'removed' => entries(array_filter($removed)),
        'changed' => $changed,
    ];
}

/**
 * Per issue type counts of a diff, to spot volatile types.
 *
 * @param array{
 *     added: list<array{type: string}>,
 *     removed: list<array{type: string}>,
 *     changed: list<array{type: string}>
 * } $diff
 * @return array<string, array{added: int, removed: int, changed: int}>
 */
function countByType(array $diff): array
{
    $types = [];
    foreach (['added', 'removed', 'changed'] as $kind) {
        foreach ($diff[$kind] as $e) {
            $types[$e['type']] ??= ['added' => 0, 'removed' => 0, 'changed' => 0];
            $types[$e['type']][$kind]++;
        }
    }

    ksort($types);

    return $types;
}

function deltaPct(float|int|null $base, float|int|null $head): ?float
{
    if ($base === null || $head === null || $base == 0) {
        return null;
    }

    return ($head - $base) / $base * 100;
}

/**
 * @param array<string, mixed> $base
 * @param array<string, mixed> $head
 * @return list<string> names of the fingerprint env keys that differ
 */
function fingerprintMismatch(array $base, array $head): array
{
    $differs = [];
    foreach (FINGERPRINT_KEYS as $key) {
        if (($base['env'][$key] ?? null) !== ($head['env'][$key] ?? null)) {
            $differs[] = $key;
        }
    }

    if (($base['mode'] ?? null) !== ($head['mode'] ?? null)) {
        $differs[] = 'mode';
    }

    return $differs;
}

/**
 * Everything compare knows about one project.
 *
 * @param array<string, mixed>|null $base
 * @param array<string, mixed>|null $head
 * @return array<string, mixed>
 */
function analyzeProject(string $name, ?array $base, ?array $head, string $baseDir, string $headDir): array
{
    $baseOk = ($base['status'] ?? null) === 'ok';
    $headOk = ($head['status'] ?? null) === 'ok';
    $p = [
        'status_base' => $base['status'] ?? null,
        'status_head' => $head['status'] ?? null,
        'mode' => $head['mode'] ?? $base['mode'] ?? null,
        'comparison_valid' => true,
        'invalid_reasons' => [],
        'metrics' => [],
        'issues' => null,
        'diff' => null,
        'gate' => [],
    ];

    if ($base === null || $head === null) {
        return $p;
    }

    $mismatch = fingerprintMismatch($base, $head);
    if ($mismatch !== []) {
        $p['comparison_valid'] = false;
        $p['invalid_reasons'] = $mismatch;
    }

    if ($baseOk && $headOk) {
        foreach (METRICS as $metric) {
            $b = $base['metrics'][$metric] ?? null;
            $h = $head['metrics'][$metric] ?? null;
            if ($b !== null && $h !== null) {
                $p['metrics'][$metric] = ['base' => $b, 'head' => $h, 'delta_pct' => deltaPct($b, $h)];
            }
        }

        if ($p['comparison_valid']) {
            foreach (['ir' => IR_GATE_PCT, 'psalm_mb' => MB_GATE_PCT] as $metric => $limit) {
                $delta = $p['metrics'][$metric]['delta_pct'] ?? null;
                if ($delta !== null && $delta > $limit) {
                    $label = $metric === 'ir' ? 'Ir' : 'Psalm MB';
                    $p['gate'][] = sprintf('%s: %s %s (gate +%d %%)', $name, $label, fmtPct($delta), (int) $limit);
                }
            }
        }

        $baseIssues = loadIssues($baseDir, $base);
        $headIssues = loadIssues($headDir, $head);
        if ($baseIssues !== null && $headIssues !== null) {
            $diff = diffIssues($baseIssues, $headIssues);
            $p['diff'] = $diff;
            $p['issues'] = [
                'base' => count($baseIssues),
                'head' => count($headIssues),
                'added' => count($diff['added']),
                'removed' => count($diff['removed']),
                'changed' => count($diff['changed']),
                'by_type' => countByType($diff),
            ];
        }
    }

    if ($baseOk && in_array($head['status'] ?? null, ['crash', 'timeout'], true)) {
        $p['gate'][] = sprintf('%s: head %s where base was ok', $name, $head['status']);
    }

    return $p;
}

function fmtPct(float $pct): string
{
    return str_replace('-', '−', sprintf('%+.2f %%', $pct));
}

/** Δ shown only when |Δ| >= 0.5 %, otherwise "≈". */
function fmtDelta(?float $pct): string
{
    if ($pct === null) {
        return 'n/a';
    }

    return abs($pct) >= SHOW_PCT ? fmtPct($pct) : '≈';
}

function fmtIr(int|float $ir): string
{
    return number_format($ir / 1e9, 2) . 'G';
}

/** Markdown inline code of arbitrary text: one line, clipped, no backticks. */
function code(string $text, int $max = CLIP_CHARS): string
{
    $text = (string) preg_replace('/\s+/u', ' ', $text);

    return '`' . str_replace('`', "'", mb_strimwidth($text, 0, $max, '…')) . '`';
}

/** @param array<string, mixed> $p */
function irCell(array $p, ?array $head): string
{
    if ($p['status_base'] === null) {
        $ir = $head['metrics']['ir'] ?? null;

        if ($p['status_head'] !== 'ok') {
            return (string) $p['status_head'];
        }

        return 'no base' . ($ir !== null ? ' (' . fmtIr($ir) . ')' : '');
    }

    if ($p['status_head'] === null) {
        return 'no head result';
    }

    if ($p['status_head'] !== 'ok') {
        return sprintf('❌ %s', $p['status_head']);
    }

    if ($p['status_base'] !== 'ok') {
        return sprintf('base %s', $p['status_base']);
    }

    $m = $p['metrics']['ir'] ?? null;
    if ($m === null) {
        return '–';
    }

    if (!$p['comparison_valid']) {
        return 'invalid';
    }

    $cell = fmtDelta($m['delta_pct']);
    if ($cell !== '≈') {
        $cell .= sprintf(' (%s → %s)', fmtIr($m['base']), fmtIr($m['head']));
    }

    return $m['delta_pct'] > IR_GATE_PCT ? $cell . ' ❌' : $cell;
}

/** @param array<string, mixed> $p */
function mbCell(array $p): string
{
    $m = $p['metrics']['psalm_mb'] ?? null;
    if ($m === null || $p['status_head'] !== 'ok' || $p['status_base'] !== 'ok') {
        return '–';
    }

    if (!$p['comparison_valid']) {
        return 'invalid';
    }

    $cell = fmtDelta($m['delta_pct']);
    if ($cell !== '≈') {
        $cell .= sprintf(' (%.1f → %.1f)', $m['base'], $m['head']);
    }

    return $m['delta_pct'] > MB_GATE_PCT ? $cell . ' ❌' : $cell;
}

/** @param array<string, mixed> $p */
function wallCell(array $p, ?array $head): string
{
    $parts = [];
    foreach (['wall_t1' => 't1', 'wall_t4' => 't4'] as $metric => $label) {
        $m = $p['metrics'][$metric] ?? null;
        $value = $m['head'] ?? $head['metrics'][$metric] ?? null;
        if ($value === null) {
            continue;
        }

        $delta = $m !== null ? fmtDelta($m['delta_pct']) : '≈';
        $parts[] = sprintf('%s %.1fs%s', $label, $value, in_array($delta, ['≈', 'n/a'], true) ? '' : " ({$delta})");
    }

    return $parts === [] ? '–' : implode(' / ', $parts);
}

/** @param array<string, mixed> $p */
function issuesCell(array $p, ?array $base, ?array $head): string
{
    $i = $p['issues'];
    if ($i === null) {
        $count = $head['issue_count'] ?? null;

        return $count === null ? '–' : (string) $count;
    }

    if ($i['added'] + $i['removed'] + $i['changed'] === 0) {
        return $i['base'] === $i['head'] ? "{$i['head']} (unchanged)" : "{$i['base']} → {$i['head']}";
    }

    return sprintf('%d → %d (+%d / −%d / ~%d)', $i['base'], $i['head'], $i['added'], $i['removed'], $i['changed']);
}

/**
 * @param array<string, mixed> $p
 */
function renderDetails(string $name, array $p): string
{
    $i = $p['issues'];
    $d = $p['diff'];
    $out = sprintf(
        "<details><summary><b>%s</b>: issues %d → %d (+%d / −%d / ~%d)</summary>\n\n",
        htmlspecialchars($name),
        $i['base'],
        $i['head'],
        $i['added'],
        $i['removed'],
        $i['changed'],
    );

    $out .= "| Type | added | removed | changed |\n|---|---|---|---|\n";
    foreach ($i['by_type'] as $type => $n) {
        $out .= sprintf("| %s | %d | %d | %d |\n", $type, $n['added'], $n['removed'], $n['changed']);
    }

    $out .= "\n";

    foreach (['added' => 'Added', 'removed' => 'Removed', 'changed' => 'Changed'] as $key => $label) {
        if ($d[$key] === []) {
            continue;
        }

        $out .= sprintf("**%s** (%d)\n\n", $label, count($d[$key]));
        foreach (array_slice($d[$key], 0, LIST_CAP) as $e) {
            if ($key === 'changed') {
                $out .= sprintf(
                    "- **%s** %s\n  - was: %s\n  - now: %s\n",
                    $e['type'],
                    code($e['loc']),
                    code($e['old']),
                    code($e['new']),
                );
            } else {
                $out .= sprintf("- **%s** %s %s\n", $e['type'], code($e['loc']), code($e['message']));
            }
        }

        if (count($d[$key]) > LIST_CAP) {
            $out .= sprintf("- … and %d more\n", count($d[$key]) - LIST_CAP);
        }

        $out .= "\n";
    }

    return $out . "</details>\n";
}

/**
 * @param array<string, mixed> $result
 */
function renderLogTail(string $name, string $side, array $result): string
{
    $lines = array_slice(explode("\n", trim((string) ($result['log_tail'] ?? ''))), -LOG_TAIL_LINES);
    $clipped = array_map(static fn(string $l): string => mb_strimwidth($l, 0, 200, '…'), $lines);
    $tail = str_replace('```', "'''", implode("\n", $clipped));

    return sprintf(
        "<details><summary><b>%s</b> %s: %s</summary>\n\n```\n%s\n```\n</details>\n",
        htmlspecialchars($name),
        $side,
        htmlspecialchars((string) ($result['status'] ?? '?')),
        $tail === '' ? '(no log)' : $tail,
    );
}
