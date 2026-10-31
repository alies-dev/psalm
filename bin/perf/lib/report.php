<?php

declare(strict_types=1);

const REPORT_MAX_BYTES = 60_000;
const DETAILS_BUDGET_BYTES = 40_000;
const LOG_TAIL_LINES = 15;
const LOG_TAIL_BYTES = 2_000;
const VERDICT_ICONS = ['pass' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'skip' => '➖'];

function fmtPct(?float $pct): string
{
    return $pct === null ? 'n/a' : str_replace('-', '−', sprintf('%+.1f %%', $pct));
}

function fmtSigned(int $n): string
{
    return str_replace('-', '−', sprintf('%+d', $n));
}

function inlineCode(string $text): string
{
    return '`' . str_replace('`', "'", $text) . '`';
}

function tableCell(string $text): string
{
    return str_replace(['|', "\n"], ['\\|', ' '], $text);
}

/**
 * @param array<string, mixed> $p
 */
function isOkPair(array $p): bool
{
    return $p['base']['status'] === 'ok' && $p['head']['status'] === 'ok';
}

/**
 * "base → head (Δ)" of a metric, "–" when one side has no measurement.
 *
 * @param array<string, mixed> $p
 */
function metricCell(array $p, string $key, string $fmt, ?float $delta): string
{
    if (!isOkPair($p)) {
        return '–';
    }

    return sprintf("{$fmt} → {$fmt} (%s)", $p['base'][$key], $p['head'][$key], fmtPct($delta));
}

/**
 * @param array<string, mixed> $p
 */
function wallCell(array $p): string
{
    $cell = metricCell($p, 'wall', '%.1fs', $p['wall_delta']);

    return $cell !== '–' && max($p['base']['runs'], $p['head']['runs']) > 1 ? $cell . ' re-run' : $cell;
}

/**
 * @param array<string, mixed> $p
 */
function issuesCell(array $p): string
{
    $i = $p['issues'] ?? null;

    return $i === null ? '–' : sprintf('%d → %d (+%d −%d)', $i['base'], $i['head'], $i['added'], $i['removed']);
}

/**
 * Informational issue score "base → head (Δ)", "–" without issue lists.
 *
 * @param array<string, mixed> $p
 */
function scoreCell(array $p): string
{
    $s = $p['score'] ?? null;

    return $s === null ? '–' : sprintf('%d → %d (%s)', $s['base'], $s['head'], fmtSigned($s['delta']));
}

/**
 * Non-zero per-level issue count deltas, e.g. "L1 +100 · always +2", or '' when none.
 *
 * @param array<string, mixed> $p
 */
function levelDeltas(array $p): string
{
    $deltas = [];
    foreach ($p['score']['by_level'] ?? [] as $label => $l) {
        if ($l['delta'] !== 0) {
            $deltas[] = "{$label} " . fmtSigned($l['delta']);
        }
    }

    return implode(' · ', $deltas);
}

/**
 * @param array<string, mixed> $summary
 */
function comparedCount(array $summary): int
{
    return count(array_filter(
        $summary['projects'],
        static fn(array $p): bool => $p['base']['status'] === 'ok'
            && in_array($p['head']['status'], ['ok', 'crash', 'timeout'], true),
    ));
}

/**
 * @param array<string, mixed> $p
 */
function detailsFor(string $name, array $p): string
{
    $parts = [];
    $i = $p['issues'] ?? null;
    if (($levels = levelDeltas($p)) !== '') {
        $parts[] = '**Score** ' . scoreCell($p) . ": {$levels}";
    }

    if ($i !== null && ($i['added'] > 0 || $i['removed'] > 0)) {
        $rows = ["| Issue type | added | removed |", '|---|---|---|'];
        foreach ($i['types'] as $type => $n) {
            $rows[] = '| ' . tableCell($type) . " | {$n['added']} | {$n['removed']} |";
        }

        $parts[] = implode("\n", $rows);

        foreach (['added', 'removed'] as $kind) {
            if ($i["{$kind}_examples"] !== []) {
                $more = $i[$kind] - count($i["{$kind}_examples"]);
                $items = array_map(static fn(string $e): string => '- ' . inlineCode($e), $i["{$kind}_examples"]);
                $parts[] = "**" . ucfirst($kind) . "** ({$i[$kind]})\n\n" . implode("\n", $items)
                    . ($more > 0 ? "\n- … and {$more} more" : '');
            }
        }
    }

    foreach (['head', 'base'] as $side) {
        if (($p[$side]['log_tail'] ?? null) !== null && $p[$side]['status'] !== 'ok') {
            $lines = array_slice(explode("\n", (string) $p[$side]['log_tail']), -LOG_TAIL_LINES);
            $log = str_replace('```', "'''", substr(implode("\n", $lines), -LOG_TAIL_BYTES));
            $parts[] = "**{$side} log tail**\n\n```text\n{$log}\n```";
        }
    }

    if ($parts === []) {
        return '';
    }

    $summary = sprintf('%s %s', VERDICT_ICONS[$p['verdict']], $name);

    return "<details><summary>{$summary}</summary>\n\n" . implode("\n\n", $parts) . "\n\n</details>\n";
}

/**
 * @param array<string, mixed> $summary result of compareAll() plus base_sha and head_sha
 */
function renderReport(array $summary, ?string $runUrl): string
{
    $total = count($summary['projects']);
    $out = sprintf(
        "## %s Perf: %s, head `%s`, %d/%d compared\n\n",
        VERDICT_ICONS[$summary['verdict']],
        $summary['verdict'],
        substr($summary['head_sha'], 0, 12),
        comparedCount($summary),
        $total,
    );
    $scores = array_filter(array_column($summary['projects'], 'score'));
    if ($scores !== []) {
        $out .= 'Issue score Δ ' . fmtSigned(array_sum(array_column($scores, 'delta')))
            . " (informational, see the README)\n\n";
    }

    $out .= "| Project | Psalm MB | Wall | Issues | Score | Status |\n|---|---|---|---|---|---|\n";
    foreach ($summary['projects'] as $name => $p) {
        $out .= sprintf(
            "| %s | %s | %s | %s | %s | %s |\n",
            $name,
            metricCell($p, 'psalm_mb', '%.0f', $p['mb_delta']),
            wallCell($p),
            issuesCell($p),
            scoreCell($p),
            trim(VERDICT_ICONS[$p['verdict']] . ' ' . tableCell(implode('; ', $p['notes']))),
        );
    }

    $out .= "\nMemory is Psalm's own peak figure (threads=1). Wall time is informational: base and head ran on the "
        . "same runner, expect ±10 % noise; \"re-run\" means a reversed second run was taken and the faster kept.\n";

    $out .= appendDetails($summary, strlen($out));

    $footer = "\nBase (merge-base): `" . substr($summary['base_sha'], 0, 12) . '`';
    $footer .= $summary['deps_changed']
        ? ". composer.json changed in this PR, so head dependencies were re-resolved; "
            . "memory and time deltas are informational.\n"
        : ".\n";
    if ($runUrl !== null) {
        $footer .= "[Workflow run]({$runUrl})\n";
    }

    return $out . $footer;
}

/**
 * Project details in table order while they fit the budgets; the table itself is never dropped.
 *
 * @param array<string, mixed> $summary
 */
function appendDetails(array $summary, int $usedBytes): string
{
    $details = '';
    $omitted = 0;
    foreach ($summary['projects'] as $name => $p) {
        $block = detailsFor($name, $p);
        $fits = strlen($details) + strlen($block) <= DETAILS_BUDGET_BYTES
            && $usedBytes + strlen($details) + strlen($block) <= REPORT_MAX_BYTES - 1_000;
        if ($block === '' || $fits) {
            $details .= $block;
        } else {
            $omitted++;
        }
    }

    $note = $omitted > 0 ? "\n{$omitted} detail block(s) omitted for size; see summary.json in the artifact.\n" : '';

    return ($details === '' ? '' : "\n" . $details) . $note;
}

/**
 * @param list<string> $args
 */
function runCompare(array $args): int
{
    $names = ['plan', 'in', 'base-sha', 'head-sha', 'deps-changed', 'out-md', 'out-json', 'run-url'];
    $options = parseOptions($args, $names);
    requireOptions($options, array_slice($names, 0, 7));
    $summary = compareAll(readJsonFile($options['plan']), $options['in'], $options['deps-changed'] === '1');
    $summary += ['base_sha' => $options['base-sha'], 'head_sha' => $options['head-sha']];
    writeJsonFile($options['out-json'], ['schema' => 1] + $summary);
    if (file_put_contents($options['out-md'], renderReport($summary, $options['run-url'] ?? null)) === false) {
        throw new RuntimeException("cannot write {$options['out-md']}");
    }

    return 0;
}
