<?php

declare(strict_types=1);

/**
 * Write stub results for projects whose run-shard.sh result is missing (job failed, was cancelled or timed out).
 *
 * Usage: php write-stubs.php --corpus <corpus.json> --out <dir> --projects <csv> --sha <psalm sha>
 * Run once per rev directory. Existing <project>.json files are never touched.
 * Stub status: .started older than the project timeout => timeout; .started younger => inconclusive
 * (interrupted); no .started => setup-failed.
 * Exit: 0 ok, 2 usage/corpus error.
 */

require __DIR__ . '/lib/measure.php';
require __DIR__ . '/lib/result.php';

$options = getopt('', ['corpus:', 'out:', 'projects:', 'sha:']);
foreach (['corpus', 'out', 'projects', 'sha'] as $required) {
    if (!isset($options[$required]) || !is_string($options[$required]) || $options[$required] === '') {
        fwrite(STDERR, "write-stubs: missing --{$required}\n");
        fwrite(STDERR, "usage: php write-stubs.php --corpus <file> --out <dir> --projects <csv> --sha <sha>\n");
        exit(2);
    }
}

$corpus = perfLoadCorpus(perfCorpusFile($options['corpus']));
$out = rtrim($options['out'], '/');
if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    fwrite(STDERR, "write-stubs: cannot create {$out}\n");
    exit(2);
}

$lockSha = is_file("{$out}/.lock_sha256") ? trim((string) file_get_contents("{$out}/.lock_sha256")) : '';
$lockSha = $lockSha === '' ? null : $lockSha;

$names = array_values(array_filter(explode(',', $options['projects']), static fn(string $p): bool => $p !== ''));
foreach (perfSelectProjects($corpus, $names) as $name => $project) {
    if (is_file("{$out}/{$name}.json")) {
        continue;
    }

    $outcome = perfStubOutcome("{$out}/{$name}.started", perfProjectTimeout($project), time());
    $stub = perfStubResult(
        $name,
        perfShardId($project),
        perfProjectMode($corpus, $project),
        $options['sha'],
        $outcome,
        perfLogTail("{$out}/{$name}.log"),
        $lockSha,
    );

    file_put_contents(
        "{$out}/{$name}.json",
        json_encode($stub, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    );
    echo "write-stubs: {$name} => {$outcome['status']} ({$outcome['reason']})\n";
}
