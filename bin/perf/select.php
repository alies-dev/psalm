<?php

declare(strict_types=1);

/**
 * Corpus sizing checks and the job plan of a perf run.
 *
 *   php select.php --lint
 *       Enforces every sizing rule of corpus.json (budget, memory, single shard, fields, files). Exit 0, or 2
 *       with one message per violation on stderr.
 *
 *   php select.php plan --event <pull_request|push|workflow_dispatch> --branch <b> --head-sha <sha>
 *       [--base-sha <merge-base>] [--bundle-dir <dir> --bundle-sha <sha> --bundle-results-usable 0|1]
 *       --fingerprints <fingerprint.php output of head's environment> --locks-info <json> --harness-sha <sha>
 *       --out-expected <file> [--include-disabled <csv>]
 *
 *   stdout: {"measure": [...], "extra": [...], "notes": [...], "t4_skipped": [{"project", "reason"}]}; --out-expected
 *   gets expected.json. `measure` and `extra` are empty when the head lock failed. A base lock_source of "failed"
 *   (pull_request) means nothing is measured or reused for base and expected.json marks the base source "none".
 *   t4_skipped (push/dispatch) lists the natives without a t4 pass because of the memory budget.
 *   Reusing the bundle also needs locks-info base sha256 == the bundle manifest's lock_sha256. Exit 0, 2 on usage
 *   or corpus errors.
 *
 * PERF_CORPUS overrides the corpus.json path (tests).
 */

require __DIR__ . '/lib/cli.php';
require __DIR__ . '/lib/corpus.php';
require __DIR__ . '/lib/validate.php';
require __DIR__ . '/lib/bundle.php';
require __DIR__ . '/lib/plan.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "select: {$message}\n");
    exit(2);
};

$loadCorpus = static function () use ($fail): array {
    $path = corpusPath();
    try {
        $corpus = readJsonFile($path);
    } catch (RuntimeException $e) {
        $fail($e->getMessage());
    }

    $problems = lintCorpus($corpus, dirname($path));
    if ($problems !== []) {
        $fail("corpus.json violates the sizing rules:\n  " . implode("\n  ", $problems));
    }

    return $corpus;
};

if (($argv[1] ?? '') === '--lint' && count($argv) === 2) {
    $corpus = $loadCorpus();
    fwrite(STDOUT, "select: corpus OK\n  " . implode("\n  ", lintSummary($corpus)) . "\n");
    exit(0);
}

if (($argv[1] ?? '') !== 'plan') {
    $fail('usage: select.php --lint | select.php plan --event ... (see the header of this file)');
}

try {
    $o = parseOptions(array_slice($argv, 2), [
        'event', 'branch', 'head-sha', 'base-sha', 'bundle-dir', 'bundle-sha', 'bundle-results-usable',
        'fingerprints', 'locks-info', 'harness-sha', 'out-expected', 'include-disabled',
    ]);
    requireOptions($o, ['event', 'branch', 'head-sha', 'fingerprints', 'locks-info', 'harness-sha', 'out-expected']);
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}

$corpus = $loadCorpus();
$event = $o['event'];
if (!in_array($event, EVENTS, true)) {
    $fail('--event must be one of ' . implode(', ', EVENTS));
}

if (!in_array($o['branch'], $corpus['branches'], true)) {
    $fail("branch {$o['branch']} is not in corpus.json branches (" . implode(', ', $corpus['branches']) . ')');
}

foreach (['head-sha', 'base-sha', 'bundle-sha'] as $key) {
    if (isset($o[$key]) && !isSha1($o[$key])) {
        $fail("--{$key} must be a 40-hex sha");
    }
}

if ($event === 'pull_request' && !isset($o['base-sha'])) {
    $fail('--base-sha is required for pull_request');
}

$includeDisabled = array_values(array_filter(explode(',', (string) ($o['include-disabled'] ?? ''))));
if ($includeDisabled !== [] && $event !== 'workflow_dispatch') {
    $fail('--include-disabled is only valid for workflow_dispatch');
}

$problems = includeDisabledProblems($corpus, $includeDisabled);
if ($problems !== []) {
    $fail(implode("\n", $problems));
}

$bundle = null;
if (isset($o['bundle-dir'])) {
    if (!isset($o['bundle-sha'])) {
        $fail('--bundle-dir needs --bundle-sha');
    }

    if (($o['bundle-results-usable'] ?? '0') === '1' && $event === 'pull_request') {
        [$manifest, $problems] = validateBundle($o['bundle-dir'], $o['bundle-sha']);
        if ($problems === [] && $manifest !== null) {
            $bundle = ['sha' => $o['bundle-sha'], 'dir' => $o['bundle-dir'], 'manifest' => $manifest];
        } else {
            fwrite(STDERR, "select: bundle results ignored, base is measured: " . implode('; ', $problems) . "\n");
        }
    }
}

try {
    $fingerprints = readJsonFile($o['fingerprints']);
    $result = buildPlan($corpus, [
        'event' => $event,
        'branch' => $o['branch'],
        'head_sha' => $o['head-sha'],
        'base_sha' => $o['base-sha'] ?? null,
        'harness_sha' => $o['harness-sha'],
        'head_env' => is_array($fingerprints['env'] ?? null) ? $fingerprints['env'] : [],
        'locks' => readJsonFile($o['locks-info']),
        'bundle' => $bundle,
        'include_disabled' => $includeDisabled,
    ]);
    writeJsonFile($o['out-expected'], $result['expected']);
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}

echo json_encode($result['plan'], JSON_UNESCAPED_SLASHES), "\n";
