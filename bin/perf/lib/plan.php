<?php

declare(strict_types=1);

const STEP_SLACK_MINUTES = 2;
const JOB_SLACK_MINUTES = 8;
/** An install must finish well inside its timeout: expected_install_seconds x this factor <= the timeout. */
const INSTALL_MARGIN = 1.2;
const MODES = ['cachegrind', 'native'];
const TIERS = ['pr', 'post-merge'];
const EVENTS = ['pull_request', 'push', 'workflow_dispatch'];

/** "10G" / "512M" to MiB. */
function parseMemoryMb(mixed $limit): ?int
{
    if (!is_string($limit) || preg_match('/^(\d+)([MG])$/i', $limit, $m) !== 1) {
        return null;
    }

    return (int) $m[1] * (strtoupper($m[2]) === 'G' ? 1024 : 1);
}

/**
 * Problems with the install timeout of a project: a per-project `install_timeout` must be a positive integer and
 * leave INSTALL_MARGIN of headroom over expected_install_seconds.
 *
 * @param array<mixed> $corpus
 * @param array<mixed> $project
 * @return list<string>
 */
function installTimeoutProblems(array $corpus, string $name, array $project): array
{
    if (array_key_exists('install_timeout', $project)
        && (!is_int($project['install_timeout']) || $project['install_timeout'] <= 0)
    ) {
        return ["project {$name}: 'install_timeout' must be a positive integer"];
    }

    $install = $project['expected_install_seconds'] ?? null;
    if (!is_int($install) && !is_float($install) || !is_int($corpus['install_timeout'] ?? null)) {
        return [];
    }

    $timeout = perfInstallTimeout($corpus, $project);
    if (INSTALL_MARGIN * $install <= $timeout) {
        return [];
    }

    return [sprintf(
        "project %s: expected_install_seconds %s x %s is above the install timeout %d s; "
        . "raise the project's 'install_timeout'",
        $name,
        $install,
        INSTALL_MARGIN,
        $timeout,
    )];
}

/**
 * @param array<mixed> $corpus
 */
function limitMb(array $corpus, string $mode): int
{
    return parseMemoryMb($corpus['memory_limit'][$mode] ?? null) ?? 0;
}

/**
 * @param array<mixed> $corpus
 * @return list<string> problems; empty means the corpus obeys every sizing rule
 */
function lintCorpus(array $corpus, string $corpusDir): array
{
    $errors = lintGlobals($corpus);
    $shards = is_array($corpus['shards'] ?? null) ? $corpus['shards'] : [];
    $projects = is_array($corpus['projects'] ?? null) ? $corpus['projects'] : [];

    foreach ($shards as $name => $shard) {
        if (!isSafeName((string) $name)) {
            $errors[] = "shard '{$name}': name must match [A-Za-z0-9][A-Za-z0-9._-]*";
        }

        if (!is_array($shard) || !in_array($shard['mode'] ?? null, MODES, true)) {
            $errors[] = "shard {$name}: mode must be one of " . implode(', ', MODES);
        }

        if (!is_array($shard) || !in_array($shard['tier'] ?? null, TIERS, true)) {
            $errors[] = "shard {$name}: tier must be one of " . implode(', ', TIERS);
        }
    }

    $used = [];
    foreach ($projects as $name => $project) {
        $name = (string) $name;
        if (!is_array($project)) {
            $errors[] = "project {$name}: must be an object";
            continue;
        }

        foreach ($project['shards'] ?? [] as $shard) {
            $used[(string) $shard] = true;
        }

        if (array_key_exists('disabled', $project)) {
            if (!is_string($project['disabled']) || trim($project['disabled']) === '') {
                $errors[] = "project {$name}: 'disabled' must be a non-empty reason string";
            }

            continue;
        }

        array_push($errors, ...lintProject($corpus, $name, $project, $corpusDir));
    }

    foreach (array_keys($shards) as $name) {
        if (!isset($used[(string) $name])) {
            $errors[] = "shard {$name}: no project belongs to it";
        }
    }

    return [...$errors, ...lintBudgets($corpus)];
}

/**
 * @param array<mixed> $corpus
 * @return list<string>
 */
function lintGlobals(array $corpus): array
{
    $errors = [];
    if (($corpus['schema'] ?? null) !== 2) {
        $errors[] = 'schema must be 2';
    }

    $branches = $corpus['branches'] ?? null;
    if (!is_array($branches) || $branches === [] || array_filter($branches, 'is_string') !== $branches) {
        $errors[] = 'branches must be a non-empty list of branch names';
    }

    if (!is_string($corpus['php'] ?? null) || preg_match('/^\d+\.\d+$/', $corpus['php']) !== 1) {
        $errors[] = "php must be a 'major.minor' string";
    }

    foreach (MODES as $mode) {
        if (parseMemoryMb($corpus['memory_limit'][$mode] ?? null) === null) {
            $errors[] = "memory_limit.{$mode} must look like '10G' or '512M'";
        }
    }

    foreach (['pr_budget_seconds', 'install_timeout'] as $key) {
        if (!is_int($corpus[$key] ?? null) || $corpus[$key] <= 0) {
            $errors[] = "{$key} must be a positive integer";
        }
    }

    $cpus = $corpus['measured_cpus'] ?? null;
    if (!is_array($cpus) || $cpus === [] || array_filter($cpus, 'is_string') !== $cpus) {
        $errors[] = 'measured_cpus must be a non-empty list of CPU model strings';
    }

    if (!is_array($corpus['shards'] ?? null) || !is_array($corpus['projects'] ?? null)) {
        $errors[] = 'shards and projects must be objects';
    }

    return $errors;
}

/**
 * @param array<mixed> $corpus
 * @param array<mixed> $project
 * @return list<string>
 */
function lintProject(array $corpus, string $name, array $project, string $corpusDir): array
{
    $errors = [];
    if (!isSafeName($name)) {
        $errors[] = "project '{$name}': name must match [A-Za-z0-9][A-Za-z0-9._-]*";
    }

    $shards = $project['shards'] ?? null;
    $mode = null;
    if (!is_array($shards) || count($shards) !== 1 || !is_string($shards[0] ?? null)) {
        $errors[] = "project {$name}: must belong to exactly one shard, got " . json_encode($shards);
    } elseif (!isset($corpus['shards'][$shards[0]]['mode'])) {
        $errors[] = "project {$name}: unknown shard {$shards[0]}";
    } else {
        $mode = $corpus['shards'][$shards[0]]['mode'];
    }

    foreach (['repo', 'tag'] as $key) {
        if (!is_string($project[$key] ?? null) || $project[$key] === '') {
            $errors[] = "project {$name}: '{$key}' is required";
        }
    }

    if (!isSha1($project['ref'] ?? null)) {
        $errors[] = "project {$name}: 'ref' must be a 40-hex commit sha";
    }

    foreach (['expected_seconds', 'expected_mb'] as $key) {
        if (!is_int($project[$key] ?? null) && !is_float($project[$key] ?? null) || $project[$key] <= 0) {
            $errors[] = "project {$name}: '{$key}' is required and must be positive";
        }
    }

    $install = $project['expected_install_seconds'] ?? null;
    if (!is_int($install) && !is_float($install) || $install < 0) {
        $errors[] = "project {$name}: 'expected_install_seconds' is required and must be >= 0";
    }

    array_push($errors, ...installTimeoutProblems($corpus, $name, $project));
    array_push($errors, ...lintModeFields($name, $project, $mode));
    array_push($errors, ...lintMemory($corpus, $name, $project, $mode));
    array_push($errors, ...lintFiles($name, $project, $corpusDir));

    return $errors;
}

/**
 * @param array<mixed> $project
 * @return list<string>
 */
function lintModeFields(string $name, array $project, ?string $mode): array
{
    if ($mode === 'cachegrind' && (!is_int($project['expected_ir'] ?? null) || $project['expected_ir'] <= 0)) {
        return ["project {$name}: cachegrind projects need a positive integer 'expected_ir'"];
    }

    if ($mode === 'native' && array_key_exists('expected_ir', $project)) {
        return ["project {$name}: native projects have no 'expected_ir'"];
    }

    return [];
}

/**
 * @param array<mixed> $corpus
 * @param array<mixed> $project
 * @return list<string>
 */
function lintMemory(array $corpus, string $name, array $project, ?string $mode): array
{
    $limit = $mode === null ? null : parseMemoryMb($corpus['memory_limit'][$mode] ?? null);
    $mb = $project['expected_mb'] ?? null;
    if ($limit === null || (!is_int($mb) && !is_float($mb)) || $mb <= 0) {
        return [];
    }

    $need = MEMORY_HEADROOM * $mb;
    if ($limit >= $need) {
        return [];
    }

    return [sprintf(
        'project %s: %s memory limit %s (%d MiB) is below 1.25 x expected_mb %s (%s MiB)',
        $name,
        $mode,
        $corpus['memory_limit'][$mode],
        $limit,
        $mb,
        round($need, 1),
    )];
}

/**
 * @param array<mixed> $project
 * @return list<string>
 */
function lintFiles(string $name, array $project, string $corpusDir): array
{
    $errors = [];
    $config = $project['config'] ?? $name;
    $lock = $project['lock'] ?? $config;
    foreach (['config' => $config, 'lock' => $lock] as $key => $value) {
        if (!isSafeName($value)) {
            $errors[] = "project {$name}: '{$key}' must be a corpus directory name";
            return $errors;
        }
    }

    if (!is_file("{$corpusDir}/corpus/{$config}/psalm.xml")) {
        $errors[] = "project {$name}: missing bin/perf/corpus/{$config}/psalm.xml";
    }

    if (($project['composer'] ?? true) !== false && !is_file("{$corpusDir}/corpus/{$lock}/composer.lock")) {
        $errors[] = "project {$name}: missing bin/perf/corpus/{$lock}/composer.lock";
    }

    return $errors;
}

/**
 * Every PR shard must fit pr_budget_seconds per revision: base and head each run the shard's projects, so the
 * budget is checked against one revision's sum (expected_seconds + expected_install_seconds).
 *
 * @param array<mixed> $corpus
 * @return list<string>
 */
function lintBudgets(array $corpus): array
{
    $budget = $corpus['pr_budget_seconds'] ?? null;
    if (!is_int($budget) || !is_array($corpus['shards'] ?? null)) {
        return [];
    }

    $errors = [];
    foreach ($corpus['shards'] as $shard => $def) {
        if (!is_array($def) || ($def['tier'] ?? null) !== 'pr') {
            continue;
        }

        $parts = [];
        foreach (perfShardProjectNames($corpus, (string) $shard) as $name) {
            $project = $corpus['projects'][$name];
            $parts[$name] = (float) ($project['expected_seconds'] ?? 0)
                + (float) ($project['expected_install_seconds'] ?? 0);
        }

        if (array_sum($parts) > $budget) {
            $detail = implode(', ', array_map(
                static fn(string $n, float $s): string => "{$n} {$s} s",
                array_keys($parts),
                $parts,
            ));
            $errors[] = sprintf(
                'shard %s: sum of expected_seconds + expected_install_seconds is %s s, above pr_budget_seconds %d (%s)',
                $shard,
                array_sum($parts),
                $budget,
                $detail,
            );
        }
    }

    return $errors;
}

/**
 * @param array<mixed> $corpus
 * @return list<string> human readable summary lines for a passing lint
 */
function lintSummary(array $corpus): array
{
    $lines = [];
    foreach ($corpus['shards'] as $shard => $def) {
        $names = perfShardProjectNames($corpus, (string) $shard);
        $seconds = 0.0;
        foreach ($names as $name) {
            $seconds += (float) $corpus['projects'][$name]['expected_seconds']
                + (float) $corpus['projects'][$name]['expected_install_seconds'];
        }

        $projects = implode(' + ', $names);
        $lines[] = sprintf('%s (%s, %s): %s, %s s', $shard, $def['tier'], $def['mode'], $projects, $seconds);
    }

    $eligible = [];
    $skipped = [];
    foreach (perfSelectProjects($corpus, []) as $name => $project) {
        $mode = $corpus['shards'][perfShardId($project)]['mode'];
        if ($mode === 'native') {
            perfT4Eligible($corpus, $project) ? $eligible[] = $name : $skipped[] = $name;
        }
    }

    $lines[] = 't4 eligible: ' . ($eligible === [] ? 'none' : implode(', ', $eligible))
        . '; skipped (memory budget): ' . ($skipped === [] ? 'none' : implode(', ', $skipped));

    return $lines;
}

/**
 * @param array<mixed> $corpus
 * @param list<string> $names
 * @param int $extraSeconds added on top, e.g. the t4 pass
 */
function stepMinutes(array $corpus, array $names, int $extraSeconds = 0): int
{
    $seconds = $extraSeconds;
    foreach ($names as $name) {
        $project = $corpus['projects'][$name];
        $seconds += perfInstallTimeout($corpus, $project) + perfAnalysisTimeout($project);
    }

    return (int) ceil($seconds / 60) + STEP_SLACK_MINUTES;
}

/**
 * Decides per project whether the bundle result can stand in for a base measurement: same environment, same Psalm
 * lock as the resolved base lock (a changed lock means the base must be measured again), a valid result.
 *
 * @param array<mixed> $corpus
 * @param array<string, list<string>> $prShards
 * @param array<string, string> $headEnv env fingerprints of head's environment
 * @param array{sha: string, dir: string, manifest: array<mixed>}|null $bundle validated bundle
 * @param string|null $baseLockSha sha256 of the base's resolved lock; null when it has none
 * @return array<string, bool>
 */
function reusableProjects(array $corpus, array $prShards, array $headEnv, ?array $bundle, ?string $baseLockSha): array
{
    $reusable = [];
    foreach ($prShards as $shard => $names) {
        foreach ($names as $name) {
            $reusable[$name] = $bundle !== null && isset($headEnv[$name]) && bundleResultReusable(
                $bundle['dir'],
                $bundle['manifest'],
                $bundle['sha'],
                $name,
                [
                    'env' => $headEnv[$name],
                    'lock_sha256' => $baseLockSha,
                    'shard' => (string) $shard,
                    'mode' => $corpus['shards'][$shard]['mode'],
                ],
            );
        }
    }

    return $reusable;
}

/**
 * @param array<mixed> $corpus
 * @param array{event: string, branch: string, head_sha: string, base_sha: ?string, harness_sha: string,
 *     head_env: array<string, string>, locks: array<mixed>, bundle: ?array<mixed>,
 *     include_disabled: list<string>} $in
 * @return array{plan: array<mixed>, expected: array<mixed>}
 * @throws RuntimeException
 */
function buildPlan(array $corpus, array $in): array
{
    $event = $in['event'];
    $isPr = $event === 'pull_request';
    $headLock = $in['locks']['head'] ?? null;
    $baseLock = $in['locks']['base'] ?? null;
    if (!is_array($headLock) || !isset($headLock['lock_source'])) {
        throw new RuntimeException('locks-info has no head entry');
    }

    if ($isPr && !is_array($baseLock)) {
        throw new RuntimeException('locks-info has no base entry for a pull_request');
    }

    $headFailed = $headLock['lock_source'] === 'failed';
    $baseFailed = $isPr && $baseLock['lock_source'] === 'failed';
    $prShards = prShards($corpus);
    // A failed base lock has no sha: nothing can match it, so nothing is reused and nothing is measured for base.
    $baseLockSha = $isPr && !$baseFailed && is_string($baseLock['sha256'] ?? null) ? $baseLock['sha256'] : null;
    $reusable = $isPr ? reusableProjects($corpus, $prShards, $in['head_env'], $in['bundle'], $baseLockSha) : [];
    $anyCached = in_array(true, $reusable, true);

    $measure = [];
    foreach ($prShards as $shard => $shardNames) {
        $baseNames = $isPr && !$baseFailed
            ? array_values(array_filter($shardNames, static fn(string $n): bool => !$reusable[$n]))
            : [];
        $revs = $baseNames === [] ? ['head'] : ['base', 'head'];
        $headStep = stepMinutes($corpus, $shardNames);
        $baseStep = $baseNames === [] ? 0 : stepMinutes($corpus, $baseNames);
        $measure[] = [
            'shard' => $shard,
            'mode' => $corpus['shards'][$shard]['mode'],
            'revs' => $revs,
            'base_projects' => $baseNames,
            'head_projects' => $shardNames,
            'base_step_timeout_minutes' => max(1, $baseStep),
            'head_step_timeout_minutes' => max(1, $headStep),
            'job_timeout_minutes' => $baseStep + $headStep + JOB_SLACK_MINUTES,
        ];
    }

    [$extra, $notes, $t4Skipped] = $isPr ? [[], [], []] : extraJobs($corpus, $in['include_disabled']);
    if ($headFailed) {
        $measure = [];
        $extra = [];
        $t4Skipped = [];
    }

    return [
        'plan' => ['measure' => $measure, 'extra' => $extra, 'notes' => $notes, 't4_skipped' => $t4Skipped],
        'expected' => buildExpected($corpus, $in, $prShards, $reusable, $anyCached, $headFailed, $baseFailed),
    ];
}

/**
 * @param array<mixed> $corpus
 * @return array<string, list<string>> PR-tier shard => enabled project names (empty shards dropped)
 */
function prShards(array $corpus): array
{
    $shards = [];
    foreach ($corpus['shards'] as $shard => $def) {
        $names = perfShardProjectNames($corpus, (string) $shard);
        if ($def['tier'] === 'pr' && $names !== []) {
            $shards[(string) $shard] = $names;
        }
    }

    return $shards;
}

/**
 * push/dispatch only: post-merge shards plus t4 for the PR-tier natives that pass the J2 memory rule.
 *
 * The third element lists the natives that get no t4 pass because of the memory budget, so the report of the
 * run can record it: their results never see run-shard's own t4 note when no extra job covers them.
 *
 * @param array<mixed> $corpus
 * @param list<string> $includeDisabled
 * @return array{0: list<array<mixed>>, 1: list<string>, 2: list<array{project: string, reason: string}>}
 *     jobs, notes and t4 skips
 */
function extraJobs(array $corpus, array $includeDisabled): array
{
    $extra = [];
    $t4Jobs = [];
    foreach ($corpus['shards'] as $shard => $def) {
        $shard = (string) $shard;
        $names = perfShardProjectNames($corpus, $shard);
        $added = array_values(array_filter(
            $includeDisabled,
            static fn(string $n): bool => perfShardId($corpus['projects'][$n]) === $shard,
        ));
        $isEligible = static fn(string $n): bool => perfT4Eligible($corpus, $corpus['projects'][$n]);

        if ($def['tier'] === 'post-merge') {
            $projects = [...$names, ...$added];
        } else {
            // PR-tier shards were measured by `measure`; here only their t4 pass (and included disabled projects) run.
            $projects = [...array_filter($names, $isEligible), ...$added];
        }

        if ($projects === []) {
            continue;
        }

        $t4 = array_values(array_filter($projects, $isEligible));
        $extraSeconds = 0;
        foreach ($t4 as $name) {
            $extraSeconds += perfAnalysisTimeout($corpus['projects'][$name]);
        }

        if ($t4 !== []) {
            array_push($t4Jobs, ...$projects);
        }

        $step = stepMinutes($corpus, $projects, $extraSeconds);
        $extra[] = [
            'shard' => $shard,
            'mode' => $def['mode'],
            'threads4' => $t4 !== [],
            'projects' => array_values($projects),
            'step_timeout_minutes' => max(1, $step),
            'job_timeout_minutes' => $step + JOB_SLACK_MINUTES,
        ];
    }

    $skipped = [];
    foreach ([...array_keys(perfSelectProjects($corpus, [])), ...$includeDisabled] as $name) {
        $project = $corpus['projects'][$name];
        $isNative = perfProjectMode($corpus, $project) === 'native';
        if ($isNative && !perfT4Eligible($corpus, $project) && !in_array($name, $t4Jobs, true)) {
            $skipped[] = ['project' => $name, 'reason' => 'memory budget'];
        }
    }

    $notes = [];
    if ($skipped !== []) {
        $notes[] = 't4 (4 threads) skipped (5 x 1.25 x expected_mb does not fit 12 GB): '
            . implode(', ', array_column($skipped, 'project'));
    }

    return [$extra, $notes, $skipped];
}

/**
 * @param array<mixed> $corpus
 * @param array<mixed> $in
 * @param array<string, list<string>> $prShards
 * @param array<string, bool> $reusable
 * @return array<mixed>
 */
function buildExpected(
    array $corpus,
    array $in,
    array $prShards,
    array $reusable,
    bool $anyCached,
    bool $headFailed,
    bool $baseFailed,
): array {
    $isPr = $in['event'] === 'pull_request';
    $bundleSha = $anyCached ? $in['bundle']['sha'] : null;
    $projects = [];
    foreach ($prShards as $shard => $names) {
        $mode = $corpus['shards'][$shard]['mode'];
        foreach ($names as $name) {
            $project = $corpus['projects'][$name];
            $projects[$name] = [
                'shard' => $shard,
                'mode' => $mode,
                'timeout' => perfAnalysisTimeout($project),
                'expected_seconds' => $project['expected_seconds'],
                'expected_ir' => $project['expected_ir'] ?? null,
                'expected_mb' => $project['expected_mb'],
                'limit_mb' => limitMb($corpus, $mode),
                'revs' => [
                    'base' => expectedBase($isPr, $reusable[$name] ?? false, $headFailed || $baseFailed, $in),
                    'head' => ['sha' => $in['head_sha'], 'source' => $headFailed ? 'none' : 'measured'],
                ],
            ];
        }
    }

    return [
        'schema' => 1,
        'event' => $in['event'],
        'branch' => $in['branch'],
        'head_sha' => $in['head_sha'],
        'base_sha' => $in['base_sha'],
        'base_sha_used' => $isPr ? ($bundleSha ?? $in['base_sha']) : null,
        'bundle_key' => $bundleSha === null ? null : bundleKey($in['branch'], $bundleSha),
        'harness_sha' => $in['harness_sha'],
        'locks' => [
            'base' => lockEntry($in['locks']['base'] ?? null),
            'head' => lockEntry($in['locks']['head']),
        ],
        'projects' => $projects,
    ];
}

/**
 * @param array{base_sha: ?string, bundle: ?array<mixed>} $in
 * @return array{sha: ?string, source: string}
 */
function expectedBase(bool $isPr, bool $cached, bool $unmeasurable, array $in): array
{
    if (!$isPr) {
        return ['sha' => null, 'source' => 'none'];
    }

    if ($cached) {
        return ['sha' => $in['bundle']['sha'], 'source' => 'cached'];
    }

    return ['sha' => $in['base_sha'], 'source' => $unmeasurable ? 'none' : 'measured'];
}

/**
 * @param array<mixed>|null $info
 * @return array{sha256: ?string, source: string}|null
 */
function lockEntry(?array $info): ?array
{
    return $info === null ? null : ['sha256' => $info['sha256'] ?? null, 'source' => $info['lock_source']];
}

/**
 * @param array<mixed> $corpus
 * @param list<string> $names projects requested through --include-disabled
 * @return list<string> problems
 */
function includeDisabledProblems(array $corpus, array $names): array
{
    $problems = [];
    foreach ($names as $name) {
        $project = $corpus['projects'][$name] ?? null;
        if (!is_array($project) || !array_key_exists('disabled', $project)) {
            $problems[] = "--include-disabled: {$name} is not a disabled project";
            continue;
        }

        $shard = $project['shards'][0] ?? null;
        $sized = is_numeric($project['expected_seconds'] ?? null)
            && is_numeric($project['expected_install_seconds'] ?? null);
        if (!is_string($shard) || !isset($corpus['shards'][$shard]) || !$sized) {
            $problems[] = "--include-disabled: {$name} needs a known shard, expected_seconds and "
                . 'expected_install_seconds to be sized';
        }

        foreach (installTimeoutProblems($corpus, $name, $project) as $problem) {
            $problems[] = "--include-disabled: {$problem}";
        }
    }

    return $problems;
}
