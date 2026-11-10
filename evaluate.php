<?php
// Compares run.php results of a base and a head revision and writes the PR comment.
//
// Usage: php evaluate.php --corpus FILE --base FILE|DIR --head FILE|DIR --base-sha SHA --head-sha SHA
//                         [--pr-body FILE] [--run-url URL] --out-md FILE --out-json FILE
// A DIR argument means every *.jsonl inside it (one per shard).

declare(strict_types=1);

const MARKER = '<!-- psalm-issue-bot -->';
const MAX_COMMENT_BYTES = 60000;
// Issue types that track Psalm's own annotation suggestions, not the reported behaviour.
const NOISE_TYPES = [
    'ClassMustBeFinal', 'MissingAbstractPureAnnotation', 'MissingImmutableAnnotation',
    'MissingInterfaceImmutableAnnotation', 'MissingOverrideAttribute', 'MissingPureAnnotation',
    'MissingPureFunctionAnnotation',
];
const SLOWER_RATIO = 3.0;
const SLOWER_MIN_MS = 3000;

$o = getopt('', ['corpus:', 'base:', 'head:', 'base-sha:', 'head-sha:', 'pr-body:', 'run-url:', 'out-md:', 'out-json:']);
foreach (['corpus', 'base', 'head', 'base-sha', 'head-sha', 'out-md', 'out-json'] as $required) {
    if (!isset($o[$required])) {
        fwrite(STDERR, "missing --$required\n");
        exit(2);
    }
}

function readJsonl(string $path): array
{
    $files = is_dir($path) ? glob(rtrim($path, '/') . '/*.jsonl') : [$path];
    $out = [];
    foreach ($files as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $out[$row['id']] = $row;
        }
    }
    return $out;
}

/** @return array<string, int> "Type L<line>: message" => count, noise types excluded */
function issueSet(array $run, bool $noise): array
{
    $set = [];
    foreach ($run['results'] as $r) {
        if (in_array($r['type'], NOISE_TYPES, true) !== $noise) {
            continue;
        }
        $key = sprintf('%s %s L%d: %s', strtoupper($r['severity']), $r['type'], $r['line_from'],
            preg_replace('/\s+/', ' ', trim($r['message'])));
        $set[$key] = ($set[$key] ?? 0) + 1;
    }
    ksort($set);
    return $set;
}

/** @return list<string> keys of $a missing from $b, with multiplicity */
function minus(array $a, array $b): array
{
    $out = [];
    foreach ($a as $k => $n) {
        for ($i = $n - ($b[$k] ?? 0); $i > 0; $i--) {
            $out[] = $k;
        }
    }
    return $out;
}

$corpus = readJsonl($o['corpus']);
$base = readJsonl($o['base']);
$head = readJsonl($o['head']);
$pr_body = isset($o['pr-body']) ? (string) @file_get_contents($o['pr-body']) : '';

preg_match_all('~(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s*:?\s+(?:https://github\.com/vimeo/psalm/issues/|#)(\d+)~i', $pr_body, $m);
$closes = array_flip(array_map('intval', $m[1]));
preg_match_all('~(?:https://github\.com/vimeo/psalm/issues/|#)(\d+)~', $pr_body, $m);
$mentions = array_flip(array_map('intval', $m[1]));

$changes = []; // category => list of rows
$missing = 0;
$counts = ['snippets' => 0, 'issues' => []];
foreach ($corpus as $id => $e) {
    $counts['snippets']++;
    foreach ($e['issues'] as $n) {
        $counts['issues'][$n] = true;
    }
    $b = $base[$id] ?? null;
    $h = $head[$id] ?? null;
    if ($b === null || $h === null) {
        $missing++;
        continue;
    }
    $row = ['id' => $id, 'entry' => $e, 'base' => $b, 'head' => $h];
    $b_ok = $b['status'] === 'ok';
    $h_ok = $h['status'] === 'ok';

    if ($b['status'] === 'timeout' || $h['status'] === 'timeout') {
        if ($b['status'] !== $h['status']) {
            $changes['timeout'][] = $row;
        }
        continue;
    }
    if ($b_ok && !$h_ok) {
        $changes['new_crash'][] = $row;
        continue;
    }
    if (!$b_ok && $h_ok) {
        $changes['crash_fixed'][] = $row;
        continue;
    }
    if (!$b_ok && !$h_ok) {
        if ($b['error'] !== $h['error']) {
            $changes['crash_changed'][] = $row;
        }
        continue;
    }
    if ($h['ms'] > SLOWER_RATIO * max(1, $b['ms']) && $h['ms'] - $b['ms'] > SLOWER_MIN_MS) {
        $changes['slower'][] = $row;
    }
    $bs = issueSet($b, false);
    $hs = issueSet($h, false);
    if ($bs !== $hs) {
        $row['removed'] = minus($bs, $hs);
        $row['added'] = minus($hs, $bs);
        $changes['changed'][] = $row;
    } elseif (issueSet($b, true) !== issueSet($h, true)) {
        $changes['noise_only'][] = $row;
    }
}

function issueLinks(array $e, array $closes, array $mentions): string
{
    $parts = [];
    foreach ($e['issues'] as $n) {
        $flag = isset($closes[$n]) ? ' (closed by this PR)' : (isset($mentions[$n]) ? ' (mentioned)' : '');
        $parts[] = sprintf('#%d%s', $n, $flag);
    }
    return implode(', ', $parts);
}

function snippetLink(array $e): string
{
    return $e['hash'] !== null
        ? sprintf('[%s](https://psalm.dev/r/%s)', $e['hash'], $e['hash'])
        : sprintf('[code block](https://github.com/vimeo/psalm/issues/%d)', $e['issues'][0]);
}

function errorLine(?string $s): string
{
    return '`' . str_replace('`', "'", mb_strimwidth(preg_replace('/\s+/', ' ', (string) $s), 0, 160, '…')) . '`';
}

$short = static fn(string $sha): string => substr($sha, 0, 9);
$md = [MARKER];
$affected_issues = [];
foreach (['new_crash', 'changed', 'crash_fixed', 'crash_changed'] as $cat) {
    foreach ($changes[$cat] ?? [] as $row) {
        foreach ($row['entry']['issues'] as $n) {
            $affected_issues[$n] = true;
        }
    }
}
$unmentioned = array_diff_key($affected_issues, $mentions);

$md[] = sprintf('### Issue reproducers changed by this PR');
$md[] = '';
$md[] = sprintf('%d reproducers from %d open issues, base `%s` vs head `%s`: **%d issues changed**%s.',
    $counts['snippets'], count($counts['issues']), $short($o['base-sha']), $short($o['head-sha']),
    count($affected_issues),
    $unmentioned ? sprintf(', %d not mentioned in the PR description', count($unmentioned)) : '');
if (isset($o['run-url'])) {
    $md[] = sprintf('[Workflow run](%s).', $o['run-url']);
}
$md[] = '';

$sections = [
    'new_crash' => 'New crashes',
    'changed' => 'Changed output',
    'crash_fixed' => 'Crashes fixed',
    'crash_changed' => 'Crash message changed',
    'timeout' => 'Timeout on one side only (may be flaky)',
    'slower' => sprintf('Slower by more than %.0fx and %d s', SLOWER_RATIO, SLOWER_MIN_MS / 1000),
];
foreach ($sections as $cat => $title) {
    $rows = $changes[$cat] ?? [];
    if (!$rows) {
        continue;
    }
    usort($rows, static fn(array $a, array $b): int => $b['entry']['issues'][0] <=> $a['entry']['issues'][0]);
    $md[] = sprintf('#### %s (%d)', $title, count($rows));
    $md[] = '';
    foreach ($rows as $row) {
        $e = $row['entry'];
        $title_text = $e['titles'][(string) $e['issues'][0]] ?? '';
        $md[] = sprintf('<details><summary>%s %s: %s</summary>', issueLinks($e, $closes, $mentions), snippetLink($e),
            htmlspecialchars(mb_strimwidth($title_text, 0, 90, '…')));
        $md[] = '';
        if ($cat === 'changed') {
            $md[] = '```diff';
            foreach ($row['removed'] as $line) {
                $md[] = '- ' . mb_strimwidth($line, 0, 300, '…');
            }
            foreach ($row['added'] as $line) {
                $md[] = '+ ' . mb_strimwidth($line, 0, 300, '…');
            }
            $md[] = '```';
        } else {
            $side = static fn(array $r): string => $r['status'] . ($r['error'] !== null ? ' ' . errorLine($r['error']) : '')
                . sprintf(' (%d ms)', $r['ms']);
            $md[] = 'base: ' . $side($row['base']) . '<br>head: ' . $side($row['head']);
        }
        $md[] = '';
        $md[] = '</details>';
    }
    $md[] = '';
}
if (!array_filter(array_intersect_key($changes, $sections))) {
    $md[] = 'No reproducer changed.';
    $md[] = '';
}
if ($changes['noise_only'] ?? []) {
    $md[] = sprintf('%d more reproducers changed only in %s.', count($changes['noise_only']),
        'annotation suggestions (' . implode(', ', array_slice(NOISE_TYPES, 0, 3)) . ', …)');
}
if ($missing) {
    $md[] = sprintf('%d reproducers have no result on one side.', $missing);
}

$text = implode("\n", $md) . "\n";
if (strlen($text) > MAX_COMMENT_BYTES) {
    $text = substr($text, 0, MAX_COMMENT_BYTES - 200);
    $text = substr($text, 0, strrpos($text, "\n</details>") ?: strlen($text)) . "\n</details>\n\n"
        . "Truncated: the full report is in the workflow run's summary.\n";
}
file_put_contents($o['out-md'], $text);

$summary = [
    'base_sha' => $o['base-sha'],
    'head_sha' => $o['head-sha'],
    'snippets' => $counts['snippets'],
    'issues' => count($counts['issues']),
    'missing' => $missing,
    'affected_issues' => array_keys($affected_issues),
    'unmentioned_issues' => array_keys($unmentioned),
    'counts' => array_map('count', $changes),
    'changes' => array_map(static fn(array $rows): array => array_map(static fn(array $r): array => [
        'id' => $r['id'],
        'issues' => $r['entry']['issues'],
        'base' => ['status' => $r['base']['status'], 'error' => $r['base']['error'], 'ms' => $r['base']['ms']],
        'head' => ['status' => $r['head']['status'], 'error' => $r['head']['error'], 'ms' => $r['head']['ms']],
        'removed' => $r['removed'] ?? [],
        'added' => $r['added'] ?? [],
    ], $rows), $changes),
];
file_put_contents($o['out-json'], json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
fprintf(STDERR, "evaluate: %d snippets, %d affected issues, counts %s\n", $counts['snippets'], count($affected_issues),
    json_encode($summary['counts']));
