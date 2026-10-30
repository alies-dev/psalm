<?php

declare(strict_types=1);

const SHOW_PCT = 0.5;
const LIST_CAP = 10;
const DETAILS_BUDGET_BYTES = 40_000;
const CLIP_CHARS = 300;
const LOG_TAIL_LINES = 15;
const OUTCOME_ICONS = ['regression' => '❌', 'inconclusive' => '⚠️', 'not_comparable' => '➖', 'compared' => ''];

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

function fmtSeconds(float $s): string
{
    return $s < 100 ? sprintf('%.1fs', $s) : sprintf('%.0fs', $s);
}

function shortSha(?string $sha): string
{
    return $sha === null || $sha === '' ? 'n/a' : substr($sha, 0, 12);
}

function shortCpu(?string $cpu): string
{
    if ($cpu === null) {
        return '?';
    }

    $name = (string) preg_replace('/\(R\)|\(TM\)|\bCPU\b|\bProcessor\b|\b\d+-Core\b|@.*$/i', '', $cpu);

    return trim((string) preg_replace('/\s+/', ' ', $name));
}

function clip(string $text, int $max = CLIP_CHARS): string
{
    return mb_strimwidth((string) preg_replace('/\s+/u', ' ', $text), 0, $max, '…');
}

/** Markdown inline code of arbitrary text: one line, clipped, no backticks. */
function code(string $text, int $max = CLIP_CHARS): string
{
    return '`' . str_replace('`', "'", clip($text, $max)) . '`';
}

function tableCell(string $text): string
{
    return str_replace('|', '\\|', clip($text));
}

/**
 * @param array<string, mixed>|null $r
 */
function wallOf(?array $r): ?float
{
    $v = $r['metrics']['wall_t1'] ?? $r['elapsed_analysis'] ?? null;

    return is_numeric($v) ? (float) $v : null;
}

/**
 * @param array<string, mixed> $p
 */
function failureCell(array $p): string
{
    foreach (['head', 'base'] as $side) {
        $status = $p["status_{$side}"];
        if ($status !== null && $status !== 'ok') {
            return ($p['outcome'] === 'regression' ? '❌ ' : '') . "{$side} {$status}";
        }
    }

    return '–';
}

/**
 * @param array<string, mixed> $p
 */
function deltaCell(array $p, string $metric, callable $fmt, float $limit): string
{
    $m = $p['metrics'][$metric] ?? null;
    if ($m === null) {
        return $metric === 'ir' && $p['mode'] === 'cachegrind' ? failureCell($p) : '–';
    }

    $cell = fmtDelta($m['delta_pct']);
    if ($cell !== '≈') {
        $cell .= sprintf(' (%s → %s)', $fmt($m['base']), $fmt($m['head']));
    }

    if ($m['delta_pct'] !== null && $m['delta_pct'] > $limit) {
        $downgraded = $metric === 'ir' && $p['env_differs'] !== [];

        return $cell . ($downgraded ? ' ⚠️' : ' ❌');
    }

    return $cell;
}

/**
 * @param array<string, mixed> $p
 */
function wallCell(array $p, bool $headOnly): string
{
    $show = static function (?array $r): string {
        if ($r === null) {
            return '–';
        }

        $wall = wallOf($r);

        return $r['status'] === 'ok' && $wall !== null ? fmtSeconds($wall) : $r['status'];
    };
    $head = $show($p['head']);
    $headCpu = shortCpu(cpuOf($p['head'] ?? []));
    if ($headOnly) {
        return "{$head} ({$headCpu})";
    }

    return sprintf('%s → %s (%s / %s)', $show($p['base']), $head, shortCpu(cpuOf($p['base'] ?? [])), $headCpu);
}

/**
 * @param array<string, mixed> $p
 */
function issuesCell(array $p, bool $headOnly): string
{
    $i = $p['issues'];
    if ($i === null) {
        $count = $p['head']['issue_count'] ?? null;

        return $headOnly && $count !== null ? (string) $count : '–';
    }

    $change = sprintf('+%d −%d ~%d', $i['added'], $i['removed'], $i['changed']);

    return sprintf('%d → %d (%s)', $i['base'], $i['head'], $change);
}

/**
 * @param array<string, mixed> $p
 */
function noteCell(array $p): string
{
    $labels = ['regression' => 'regression', 'inconclusive' => 'inconclusive', 'not_comparable' => 'not comparable'];
    $parts = [];
    if (isset($labels[$p['outcome']])) {
        $parts[] = $labels[$p['outcome']] . ': ' . implode('; ', $p['reasons']);
    }

    if ($p['env_differs'] !== []) {
        $parts[] = 'env differs: libs ' . implode(', ', array_slice($p['env_differs'], 0, 5))
            . (count($p['env_differs']) > 5 ? ', …' : '');
    }

    return implode('; ', [...$parts, ...$p['near'], ...$p['warnings']]);
}

/**
 * @param array<string, array<string, mixed>> $projects
 */
function renderTable(array $projects, bool $headOnly): string
{
    $header = $headOnly
        ? ['Project', 'Ir', 'MB', 'wall t1 (CPU)', 'issues', 'Note']
        : [
            'Project',
            'Ir Δ',
            'MB Δ',
            'wall t1 base → head (CPU base / CPU head)',
            'issues base → head (+added −removed ~changed)',
            'Note',
        ];
    $out = '| ' . implode(' | ', $header) . " |\n|" . str_repeat('---|', count($header)) . "\n";

    foreach ($projects as $name => $p) {
        $icon = OUTCOME_ICONS[$p['outcome']];
        if ($headOnly) {
            $ir = metricOf($p['head'] ?? [], 'ir');
            $mb = metricOf($p['head'] ?? [], 'psalm_mb');
            $cells = [
                $ir === null ? ($p['mode'] === 'cachegrind' ? failureCell($p) : '–') : fmtIr($ir),
                $mb === null ? '–' : sprintf('%.0f', $mb),
            ];
        } else {
            $cells = [
                deltaCell($p, 'ir', fmtIr(...), IR_REGRESSION_PCT),
                deltaCell($p, 'psalm_mb', static fn(float|int $mb): string => sprintf('%.0f', $mb), MB_REGRESSION_PCT),
            ];
        }

        $row = [trim("{$icon} {$name}"), ...$cells, wallCell($p, $headOnly), issuesCell($p, $headOnly), noteCell($p)];
        $out .= '| ' . implode(' | ', array_map(tableCell(...), $row)) . " |\n";
    }

    return $out;
}

/**
 * @param array<string, int> $counts
 */
function renderHeaderLine(string $verdict, bool $headOnly, string $headSha, array $counts): string
{
    $label = match (true) {
        $headOnly && $verdict === 'pass' => '✅ **Head measured**',
        $headOnly => '⚠️ **Head measurement incomplete**',
        $verdict === 'fail' => '❌ **Regression**',
        $verdict === 'inconclusive' => '⚠️ **Inconclusive**',
        default => '✅ **No regression**',
    };

    $line = sprintf(
        '%s: head `%s`, %d/%d %s',
        $label,
        shortSha($headSha),
        $counts['compared'] + $counts['regression'],
        $counts['total'],
        $headOnly ? 'measured' : 'compared',
    );
    if ($counts['regression'] > 0 && !$headOnly) {
        $line .= sprintf(', %d regressed', $counts['regression']);
    }

    return $line . sprintf(', %d not comparable, %d inconclusive', $counts['not_comparable'], $counts['inconclusive']);
}

/**
 * @param array<string, mixed> $p
 */
function renderIssueDetails(string $name, array $p): string
{
    $i = $p['issues'];
    $d = $p['diff'];
    $out = sprintf(
        "<details><summary><b>%s</b>: issues %d → %d (+%d −%d ~%d)</summary>\n\n",
        htmlspecialchars($name),
        $i['base'],
        $i['head'],
        $i['added'],
        $i['removed'],
        $i['changed'],
    );

    $out .= "| Type | added | removed | changed |\n|---|---|---|---|\n";
    foreach ($i['by_type'] as $type => $n) {
        $out .= sprintf("| %s | %d | %d | %d |\n", tableCell($type), $n['added'], $n['removed'], $n['changed']);
    }

    $out .= "\n";

    foreach (['added' => 'Added', 'removed' => 'Removed', 'changed' => 'Changed'] as $key => $label) {
        if ($d[$key] === []) {
            continue;
        }

        $out .= sprintf("**%s** (%d)\n\n", $label, count($d[$key]));
        foreach (array_slice($d[$key], 0, LIST_CAP) as $e) {
            $out .= $key === 'changed' ? renderChanged($e) : renderEntry($e);
        }

        if (count($d[$key]) > LIST_CAP) {
            $out .= sprintf("- … and %d more\n", count($d[$key]) - LIST_CAP);
        }

        $out .= "\n";
    }

    return $out . "</details>\n";
}

/**
 * @param array{type: string, severity: string, loc: string, message: string} $e
 */
function renderEntry(array $e): string
{
    return sprintf("- **%s** (%s) %s %s\n", $e['type'], $e['severity'], code($e['loc']), code($e['message']));
}

/**
 * @param array{type: string, loc: string, old: string, new: string, old_severity: string, new_severity: string} $e
 */
function renderChanged(array $e): string
{
    $out = sprintf("- **%s** %s\n", $e['type'], code($e['loc']));
    if ($e['old_severity'] !== $e['new_severity']) {
        $out .= sprintf("  - severity: %s → %s\n", $e['old_severity'], $e['new_severity']);
    }

    if ($e['old'] !== $e['new']) {
        $out .= sprintf("  - was: %s\n  - now: %s\n", code($e['old']), code($e['new']));
    }

    return $out;
}

/**
 * Log tail of a run that did not finish ok.
 *
 * @param array<string, mixed> $result
 */
function renderLogTail(string $name, string $side, array $result): string
{
    $lines = array_slice(explode("\n", trim((string) ($result['log_tail'] ?? ''))), -LOG_TAIL_LINES);
    $clipLine = static fn(string $l): string => mb_strimwidth($l, 0, 200, '…');
    $text = str_replace('```', "'''", implode("\n", array_map($clipLine, $lines)));
    if (trim($text) === '') {
        $text = '(no log) ' . trim((string) ($result['reason'] ?? ''));
    }

    return sprintf(
        "<details><summary><b>%s</b> %s: %s</summary>\n\n```\n%s\n```\n</details>\n",
        htmlspecialchars($name),
        $side,
        htmlspecialchars((string) $result['status']),
        $text,
    );
}

/**
 * Issue diffs and log tails, capped at DETAILS_BUDGET_BYTES in total.
 *
 * @param array<string, array<string, mixed>> $projects
 */
function renderDetails(array $projects): string
{
    $blocks = ['issues' => '', 'logs' => ''];
    $used = 0;
    $skipped = 0;
    $add = static function (string $section, string $block) use (&$blocks, &$used, &$skipped): void {
        if ($used + strlen($block) > DETAILS_BUDGET_BYTES) {
            $skipped++;

            return;
        }

        $used += strlen($block);
        $blocks[$section] .= $block . "\n";
    };

    foreach ($projects as $name => $p) {
        foreach (['base', 'head'] as $side) {
            $r = $p[$side];
            if ($r !== null && $r['status'] !== 'ok' && !($side === 'base' && $p['outcome'] !== 'not_comparable')) {
                $add('logs', renderLogTail($name, $side, $r));
            }
        }

        if ($p['issues'] !== null && $p['issues']['added'] + $p['issues']['removed'] + $p['issues']['changed'] > 0) {
            $add('issues', renderIssueDetails($name, $p));
        }
    }

    $md = '';
    if ($blocks['logs'] !== '') {
        $md .= "\n### Runs that did not finish\n\n" . $blocks['logs'];
    }

    if ($blocks['issues'] !== '') {
        $md .= "\n### Issue changes (threads=1)\n\n" . $blocks['issues'];
    }

    if ($skipped > 0) {
        $md .= sprintf("\n_%d detail block(s) omitted: %d byte cap._\n", $skipped, DETAILS_BUDGET_BYTES);
    }

    return $md;
}

/**
 * @param array<string, mixed> $change one entry of resolve-lock's "changed"
 */
function describePackageChange(array $change): string
{
    $name = (string) ($change['name'] ?? '?');
    $from = $change['from'] ?? null;
    $to = $change['to'] ?? null;
    $refs = static fn(): string => shortSha($change['from_ref'] ?? null)
        . ' → ' . shortSha($change['to_ref'] ?? null);

    return match (true) {
        $from === null => "{$name} added ({$to})",
        $to === null => "{$name} removed ({$from})",
        $from === $to => "upstream re-tagged {$name} {$from} ({$refs()})",
        default => "{$name} {$from} → {$to}",
    };
}

/**
 * @param array<string, mixed> $expected
 * @param array<string, mixed> $locksInfo
 * @param array<string, array<string, mixed>> $projects
 */
function renderFooter(array $expected, array $locksInfo, array $projects, bool $headOnly, ?string $runUrl): string
{
    $lines = [];
    $event = (string) ($expected['event'] ?? '?');
    $branch = (string) ($expected['branch'] ?? '?');
    if ($headOnly) {
        $lines[] = sprintf(
            '%s on `%s`, head `%s`, no base comparison',
            $event,
            $branch,
            shortSha($expected['head_sha'] ?? null),
        );
    } else {
        $used = (string) ($expected['base_sha_used'] ?? '');
        $mergeBase = (string) ($expected['base_sha'] ?? '');
        $base = sprintf('base: merge-base `%s` of `%s`', shortSha($mergeBase), $branch);
        if ($used !== '' && $used !== $mergeBase) {
            $base .= sprintf(', results from ancestor `%s`', shortSha($used));
        }

        $lines[] = $base;
    }

    $source = static fn(string $rev): string => (string) (
        $locksInfo[$rev]['lock_source'] ?? $expected['locks'][$rev]['source'] ?? 'n/a'
    );
    $lines[] = $headOnly
        ? 'lock: ' . $source('head')
        : sprintf('locks: base %s, head %s', $source('base'), $source('head'));

    $changes = $locksInfo['head']['changed'] ?? [];
    if (!$headOnly && is_array($changes) && $changes !== []) {
        $described = array_map(describePackageChange(...), array_slice($changes, 0, LIST_CAP));
        $more = count($changes) > LIST_CAP ? sprintf(' (+%d more)', count($changes) - LIST_CAP) : '';
        $lines[] = 'package differences, head lock vs base lock: ' . implode('; ', $described) . $more;
    }

    $env = [];
    foreach ($projects as $p) {
        $env = $p['head']['env'] ?? $p['base']['env'] ?? [];
        if ($env !== []) {
            break;
        }
    }

    $tools = 'PHP ' . ($env['php'] ?? 'n/a');
    if (!empty($env['valgrind'])) {
        $tools .= ', valgrind ' . $env['valgrind'];
    }

    $lines[] = $tools;
    if ($runUrl !== null && $runUrl !== '') {
        $lines[] = sprintf('[workflow run](%s)', $runUrl);
    }

    $escaped = array_map(static fn(string $l): string => htmlspecialchars($l, ENT_NOQUOTES), $lines);

    return '<sub>' . implode('<br>', $escaped) . "</sub>\n";
}

/**
 * @param array<string, array<string, mixed>> $projects
 * @return array<string, int>
 */
function countOutcomes(array $projects): array
{
    $counts = [
        'total' => count($projects),
        'compared' => 0,
        'regression' => 0,
        'not_comparable' => 0,
        'inconclusive' => 0,
    ];
    foreach ($projects as $p) {
        $counts[$p['outcome']]++;
    }

    return $counts;
}

/**
 * @param array<string, int> $counts
 */
function verdictOf(array $counts): string
{
    return match (true) {
        $counts['regression'] > 0 => 'fail',
        $counts['inconclusive'] > 0 => 'inconclusive',
        default => 'pass',
    };
}

/**
 * @param array<string, array<string, mixed>> $projects
 * @return list<string>
 */
function verdictReasons(array $projects): array
{
    $reasons = [];
    foreach (['regression', 'inconclusive'] as $outcome) {
        foreach ($projects as $name => $p) {
            if ($p['outcome'] === $outcome) {
                array_push($reasons, ...array_map(static fn(string $r): string => "{$name}: {$r}", $p['reasons']));
            }
        }
    }

    return $reasons;
}

/**
 * @param array<string, array<string, mixed>> $projects
 * @param array<string, int> $counts
 * @param list<string> $reasons
 * @return array<string, mixed>
 */
function buildSummary(array $expected, array $projects, array $counts, string $verdict, array $reasons): array
{
    $entries = [];
    foreach ($projects as $name => $p) {
        $entries[$name] = [
            'outcome' => $p['outcome'],
            'base_source' => $p['base_source'],
            'status_base' => $p['status_base'],
            'status_head' => $p['status_head'],
            'reasons' => $p['reasons'],
            'warnings' => [...$p['near'], ...$p['warnings']],
            'env_differs' => $p['env_differs'],
            'metrics' => $p['metrics'],
            'cpu' => ['base' => cpuOf($p['base'] ?? []), 'head' => cpuOf($p['head'] ?? [])],
            'issues' => $p['issues'],
        ];
    }

    return [
        'schema' => 2,
        'verdict' => $verdict,
        'event' => $expected['event'] ?? null,
        'head_sha' => $expected['head_sha'] ?? null,
        'base_sha' => $expected['base_sha'] ?? null,
        'counts' => $counts,
        'projects' => $entries,
        'reasons' => $reasons,
    ];
}
