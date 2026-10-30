<?php

declare(strict_types=1);

/**
 * Everything run-shard.sh needs from corpus.json, with every sizing rule applied by lib/corpus.php so the shell
 * never re-derives timeouts or t4 eligibility.
 *
 * Usage: php project-info.php --corpus <corpus.json> --shard <id> [--projects <csv>]
 * Output: {"mode", "memory_limit", "cpu", "cpu_measured", "projects": [{"name", "repo", "ref", "tag", "composer",
 *          "setup", "config", "lock", "expected_mb", "timeout", "install_timeout", "t4_eligible", "t4_limit_mb"}]}
 * Exit: 0 ok, 2 usage/corpus error (unknown shard or project, no memory limit for the mode).
 */

require __DIR__ . '/lib/corpus.php';
require __DIR__ . '/lib/measure.php';

$options = getopt('', ['corpus:', 'shard:', 'projects:']);
foreach (['corpus', 'shard'] as $required) {
    if (!isset($options[$required]) || !is_string($options[$required]) || $options[$required] === '') {
        fwrite(STDERR, "project-info: missing --{$required}\n");
        fwrite(STDERR, "usage: php project-info.php --corpus <file> --shard <id> [--projects <csv>]\n");
        exit(2);
    }
}

$corpus = perfLoadCorpus(perfCorpusFile($options['corpus']));
$shard = $options['shard'];
if (!isset($corpus['shards'][$shard])) {
    fwrite(STDERR, "project-info: unknown shard: {$shard}\n");
    exit(2);
}

$mode = perfShardMode($corpus, $shard);
$memoryLimit = $corpus['memory_limit'][$mode] ?? null;
if (!is_string($memoryLimit) || $memoryLimit === '') {
    fwrite(STDERR, "project-info: corpus.json has no memory_limit.{$mode}\n");
    exit(2);
}

$only = array_values(array_filter(
    explode(',', is_string($options['projects'] ?? null) ? $options['projects'] : ''),
    static fn(string $p): bool => $p !== '',
));

$projects = [];
foreach (perfShardProjects($corpus, $shard, $only) as $name => $project) {
    $projects[] = [
        'name' => $name,
        'repo' => $project['repo'] ?? '',
        'ref' => $project['ref'] ?? '',
        'tag' => $project['tag'] ?? '',
        'composer' => ($project['composer'] ?? true) !== false,
        'setup' => $project['setup'] ?? '',
        'config' => $project['config'] ?? $name,
        'lock' => $project['lock'] ?? $project['config'] ?? $name,
        'expected_mb' => $project['expected_mb'] ?? null,
        'timeout' => perfAnalysisTimeout($project),
        'install_timeout' => perfInstallTimeout($corpus, $project),
        't4_eligible' => perfT4Eligible($corpus, $project),
        't4_limit_mb' => perfT4MemoryLimitMb($project),
    ];
}

$cpu = perfCpuModel();
echo json_encode(
    [
        'mode' => $mode,
        'memory_limit' => $memoryLimit,
        'cpu' => $cpu,
        'cpu_measured' => perfCpuMeasured($corpus, $cpu),
        'projects' => $projects,
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
), "\n";
