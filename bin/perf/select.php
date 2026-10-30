<?php

declare(strict_types=1);

/**
 * Build the measure job matrix from corpus.json.
 *
 * Usage: php select.php --event <pull_request|push|workflow_dispatch> --head-sha <sha>
 *                       [--base-sha <sha>] [--cached-shards <csv>]
 *
 * pull_request: every `tier: pr` shard for head, plus the same shards for base unless
 *               listed in --cached-shards (base results already exist).
 * push / workflow_dispatch: every shard (pr and post-merge) for head only.
 *
 * Prints {"matrix": [{"shard", "rev", "sha", "mode", "timeout_minutes"}, ...]}.
 * Shards without projects are skipped. Exit: 0 ok, 2 usage/corpus error.
 */

// A job runs its projects one after another, each killed after its own `timeout` (default 900 s). The job limit
// must outlive the sum of those plus setup and upload, or a hung project would cancel the job and lose the results
// of the projects that did finish.
$defaultProjectTimeoutSeconds = 900;
$jobOverheadMinutes = 7;

$fail = static function (string $message): never {
    fwrite(STDERR, "select: {$message}\n");
    exit(2);
};

$options = getopt('', ['event:', 'head-sha:', 'base-sha:', 'cached-shards:']);
$event = $options['event'] ?? null;
$headSha = $options['head-sha'] ?? null;
$baseSha = $options['base-sha'] ?? null;
$cached = array_filter(
    explode(',', (string) ($options['cached-shards'] ?? '')),
    static fn(string $s): bool => $s !== '',
);

if (!in_array($event, ['pull_request', 'push', 'workflow_dispatch'], true) || !is_string($headSha) || $headSha === '') {
    $fail(
        'usage: --event <pull_request|push|workflow_dispatch> --head-sha <sha>'
        . ' [--base-sha <sha>] [--cached-shards <csv>]',
    );
}

if ($event === 'pull_request' && (!is_string($baseSha) || $baseSha === '')) {
    $fail('--base-sha is required for pull_request');
}

$corpus = json_decode((string) file_get_contents(__DIR__ . '/corpus.json'), true);
if (!is_array($corpus) || !is_array($corpus['shards'] ?? null) || !is_array($corpus['projects'] ?? null)) {
    $fail('corpus.json is missing or malformed');
}

/** @var array<string, int> $projectCount */
$projectCount = [];
/** @var array<string, int> $shardSeconds */
$shardSeconds = [];
foreach ($corpus['projects'] as $name => $project) {
    foreach ($project['shards'] ?? [] as $shard) {
        if (!isset($corpus['shards'][$shard])) {
            $fail("project {$name} references unknown shard {$shard}");
        }

        $projectCount[$shard] = ($projectCount[$shard] ?? 0) + 1;
        $timeout = (int) ($project['timeout'] ?? $defaultProjectTimeoutSeconds);
        $shardSeconds[$shard] = ($shardSeconds[$shard] ?? 0) + $timeout;
    }
}

$matrix = [];
$add = static function (
    string $shard,
    array $def,
    string $rev,
    string $sha,
) use (
    &$matrix,
    $shardSeconds,
    $jobOverheadMinutes,
): void {
    $matrix[] = [
        'shard' => $shard,
        'rev' => $rev,
        'sha' => $sha,
        'mode' => $def['mode'],
        // native shards run every project twice (threads=1, threads=4)
        'timeout_minutes' => $def['timeout_minutes']
            ?? (int) ceil($shardSeconds[$shard] * ($def['mode'] === 'native' ? 2 : 1) / 60) + $jobOverheadMinutes,
    ];
};

$selected = [];
foreach ($corpus['shards'] as $shard => $def) {
    if (!isset($projectCount[$shard])) {
        fwrite(STDERR, "select: skipping shard {$shard} (no projects)\n");
        continue;
    }

    if ($event === 'pull_request' && $def['tier'] !== 'pr') {
        continue;
    }

    $selected[$shard] = $def;
}

foreach ($selected as $shard => $def) {
    $add((string) $shard, $def, 'head', $headSha);
}

if ($event === 'pull_request') {
    foreach ($selected as $shard => $def) {
        if (!in_array((string) $shard, $cached, true)) {
            $add((string) $shard, $def, 'base', (string) $baseSha);
        }
    }
}

echo json_encode(['matrix' => $matrix], JSON_UNESCAPED_SLASHES), "\n";
