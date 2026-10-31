<?php

declare(strict_types=1);

const BUNDLE_SCHEMA = 1;
/** Statuses a PR-tier head result may have for the bundle to be saved. */
const BUNDLE_STORED_STATUSES = ['ok', 'crash', 'timeout', 'inconclusive'];
/** Statuses a stored result may be reused as a base for. */
const BUNDLE_REUSABLE_STATUSES = ['ok', 'crash', 'timeout'];
const BUNDLE_LOCK_SOURCES = ['reused', 'minimal', 'fresh'];

function bundleKey(string $branch, string $sha): string
{
    return "perf-base-{$branch}-{$sha}";
}

/**
 * Problems that make a result unusable as a stored head result of a bundle: the project name, a storable status
 * and everything perfResultProblems() (the validation of the report) demands of it.
 *
 * @param array<mixed> $result decoded <project>.json
 * @param array<mixed> $expected expected.json entry of the project
 * @param string $lockSha sha256 of the Psalm lock the head results were measured with
 * @return list<string>
 */
function storableResultProblems(
    string $dir,
    string $project,
    array $result,
    array $expected,
    string $sha,
    string $lockSha,
): array {
    $problems = [];
    if (($result['project'] ?? null) !== $project) {
        $problems[] = 'project is ' . json_encode($result['project'] ?? null) . ", expected {$project}";
    }

    $status = $result['status'] ?? null;
    if (!in_array($status, BUNDLE_STORED_STATUSES, true)) {
        return [...$problems, 'status ' . json_encode($status) . ' cannot be stored (setup-failed or unknown)'];
    }

    $mode = is_string($expected['mode'] ?? null) ? $expected['mode'] : '';
    $identity = ['sha' => $sha, 'lock_sha256' => $lockSha];
    array_push($problems, ...perfResultProblems($result, $identity, $expected, $mode, $dir));

    $fingerprint = $result['env']['fingerprint'] ?? null;
    if (in_array($status, BUNDLE_REUSABLE_STATUSES, true) && !isSha256($fingerprint)) {
        $problems[] = 'env.fingerprint is missing';
    }

    return $problems;
}

/**
 * Writes the bundle when every expected PR-tier head result is storable. Nothing is written otherwise.
 *
 * @param array<mixed> $expected decoded expected.json
 * @param array<mixed> $lockInfo the head entry of locks-info.json
 * @return list<string> reasons the bundle is not eligible; empty when it was written
 */
function buildBundle(
    string $headDir,
    array $expected,
    string $lockFile,
    array $lockInfo,
    string $harnessSha,
    string $outDir,
): array {
    $sha = $expected['head_sha'] ?? null;
    $problems = [];
    if (($expected['event'] ?? null) !== 'push') {
        $problems[] = 'bundles are saved by push runs only, this run is ' . json_encode($expected['event'] ?? null);
    }

    if (!isSha1($sha)) {
        $problems[] = 'expected.json has no 40-hex head_sha';
    }

    if (!in_array($lockInfo['lock_source'] ?? null, BUNDLE_LOCK_SOURCES, true)) {
        $problems[] = 'head lock_source is ' . json_encode($lockInfo['lock_source'] ?? null);
    }

    $lockSha = perfFileSha256($lockFile);
    $wantLock = $expected['locks']['head']['sha256'] ?? null;
    if ($lockSha === null || ($wantLock !== null && $wantLock !== $lockSha)) {
        $problems[] = 'lock file is missing or does not match expected.json locks.head.sha256';
    }

    $results = [];
    $projects = is_array($expected['projects'] ?? null) ? $expected['projects'] : [];
    if ($projects === []) {
        $problems[] = 'expected.json lists no projects';
    }

    foreach ($projects as $name => $entry) {
        $name = (string) $name;
        $file = "{$headDir}/{$name}.json";
        $result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!isSafeName($name) || !is_array($result)) {
            $problems[] = "{$name}: result file missing or not valid JSON";
            continue;
        }

        $found = storableResultProblems($headDir, $name, $result, $entry, (string) $sha, (string) $lockSha);
        foreach ($found as $problem) {
            $problems[] = "{$name}: {$problem}";
        }

        $results[$name] = $result;
    }

    if ($problems !== []) {
        return $problems;
    }

    writeBundle($headDir, $expected, $results, (string) $lockSha, $lockFile, $lockInfo, $harnessSha, $outDir);

    return [];
}

/**
 * @param array<mixed> $expected
 * @param array<string, array<mixed>> $results
 * @param array<mixed> $lockInfo
 */
function writeBundle(
    string $headDir,
    array $expected,
    array $results,
    string $lockSha,
    string $lockFile,
    array $lockInfo,
    string $harnessSha,
    string $outDir,
): void {
    if (!is_dir("{$outDir}/results") && !mkdir("{$outDir}/results", 0777, true)) {
        throw new RuntimeException("cannot create {$outDir}/results");
    }

    $manifest = [
        'schema' => BUNDLE_SCHEMA,
        'branch' => $expected['branch'],
        'psalm_sha' => $expected['head_sha'],
        'harness_sha' => $harnessSha,
        'lock_sha256' => $lockSha,
        'lock_source' => $lockInfo['lock_source'],
        'env_fingerprints' => [],
        'soft' => [],
        'result_sha256' => [],
        'issues_sha256' => [],
    ];
    foreach ($results as $name => $result) {
        $target = "{$outDir}/results/{$name}.json";
        copy("{$headDir}/{$name}.json", $target) || throw new RuntimeException("cannot copy {$name}.json");
        $manifest['result_sha256'][$name] = perfFileSha256($target);
        $manifest['env_fingerprints'][$name] = $result['env']['fingerprint'] ?? null;
        $manifest['soft'][$name] = $result['env']['soft'] ?? (object) [];

        $issues = is_string($result['issues_file'] ?? null) ? basename($result['issues_file']) : '';
        if ($issues !== '' && is_file("{$headDir}/{$issues}")) {
            $target = "{$outDir}/results/{$issues}";
            copy("{$headDir}/{$issues}", $target) || throw new RuntimeException("cannot copy {$issues}");
            $manifest['issues_sha256'][$name] = perfFileSha256($target);
        }
    }

    copy($lockFile, "{$outDir}/psalm-composer.lock") || throw new RuntimeException('cannot copy the lock file');
    writeJsonFile("{$outDir}/manifest.json", $manifest);
}

/**
 * Checks manifest, lock hash and every stored result against each other and the expected sha.
 *
 * @return array{0: ?array<mixed>, 1: list<string>} manifest (null when unreadable) and problems
 */
function validateBundle(string $dir, string $sha): array
{
    try {
        $manifest = readJsonFile("{$dir}/manifest.json");
    } catch (RuntimeException $e) {
        return [null, [$e->getMessage()]];
    }

    $problems = [];
    if (($manifest['schema'] ?? null) !== BUNDLE_SCHEMA) {
        $problems[] = 'manifest schema is not ' . BUNDLE_SCHEMA;
    }

    if (($manifest['psalm_sha'] ?? null) !== $sha) {
        $problems[] = 'manifest psalm_sha ' . json_encode($manifest['psalm_sha'] ?? null) . " is not {$sha}";
    }

    foreach (['branch', 'harness_sha', 'lock_source'] as $key) {
        if (!is_string($manifest[$key] ?? null) || $manifest[$key] === '') {
            $problems[] = "manifest {$key} is missing";
        }
    }

    if (!isSha256($manifest['lock_sha256'] ?? null)) {
        $problems[] = 'manifest lock_sha256 is missing';
    }

    if (perfFileSha256("{$dir}/psalm-composer.lock") !== ($manifest['lock_sha256'] ?? null)) {
        $problems[] = 'psalm-composer.lock does not match manifest lock_sha256';
    }

    $fingerprints = $manifest['env_fingerprints'] ?? null;
    if (!is_array($fingerprints) || $fingerprints === []) {
        return [$manifest, [...$problems, 'manifest lists no projects']];
    }

    foreach ($fingerprints as $name => $fingerprint) {
        foreach (storedResultProblems($dir, $manifest, $sha, (string) $name, $fingerprint) as $problem) {
            $problems[] = "{$name}: {$problem}";
        }
    }

    return [$manifest, $problems];
}

/**
 * Every stored result must pass the validation the report applies, against the manifest's sha and lock.
 *
 * @param array<mixed> $manifest
 * @return list<string>
 */
function storedResultProblems(string $dir, array $manifest, string $sha, string $name, mixed $fingerprint): array
{
    $file = "{$dir}/results/{$name}.json";
    if (!isSafeName($name) || !is_file($file)) {
        return ['result file is missing'];
    }

    if (perfFileSha256($file) !== ($manifest['result_sha256'][$name] ?? null)) {
        return ['result file does not match manifest result_sha256'];
    }

    $result = json_decode((string) file_get_contents($file), true);
    if (!is_array($result)) {
        return ['result file is not valid JSON'];
    }

    $problems = [];
    if (($result['project'] ?? null) !== $name) {
        $problems[] = 'project of the result differs from the manifest';
    }

    if (!in_array($result['status'] ?? null, BUNDLE_STORED_STATUSES, true)) {
        $problems[] = 'status ' . json_encode($result['status'] ?? null) . ' is not storable';
    }

    $mode = $result['mode'] ?? null;
    if ($mode !== 'cachegrind' && $mode !== 'native') {
        $problems[] = 'mode is ' . json_encode($mode);
    }

    $expectedRev = ['sha' => $sha, 'lock_sha256' => $manifest['lock_sha256'] ?? null];
    array_push($problems, ...perfResultProblems($result, $expectedRev, [], (string) $mode, "{$dir}/results"));

    if (($result['env']['fingerprint'] ?? null) !== $fingerprint) {
        $problems[] = 'env fingerprint differs from the manifest';
    }

    if (($result['status'] ?? null) === 'ok') {
        $issues = is_string($result['issues_file'] ?? null) ? basename($result['issues_file']) : '';
        $expectedHash = $manifest['issues_sha256'][$name] ?? null;
        if ($issues === '' || $expectedHash === null || perfFileSha256("{$dir}/results/{$issues}") !== $expectedHash) {
            $problems[] = 'issues file is missing or does not match manifest issues_sha256';
        }
    }

    return $problems;
}

/**
 * Reuse rule: a stored ok/crash/timeout result that the report would accept as the base of this run: same
 * environment, same Psalm lock as the base's resolved lock, same shard and mode as in the current corpus, and
 * valid by perfResultProblems().
 *
 * @param array<mixed> $manifest validated manifest
 * @param array{env: string, lock_sha256: ?string, shard: string, mode: string} $want
 */
function bundleResultReusable(string $dir, array $manifest, string $sha, string $name, array $want): bool
{
    $lock = $manifest['lock_sha256'] ?? null;
    if (!isSafeName($name)
        || ($manifest['env_fingerprints'][$name] ?? null) !== $want['env']
        || !isSha256($lock)
        || $lock !== $want['lock_sha256']
    ) {
        return false;
    }

    $file = "{$dir}/results/{$name}.json";
    $result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($result)
        && ($result['project'] ?? null) === $name
        && in_array($result['status'] ?? null, BUNDLE_REUSABLE_STATUSES, true)
        && perfResultProblems(
            $result,
            ['sha' => $sha, 'lock_sha256' => $lock],
            ['shard' => $want['shard']],
            $want['mode'],
            "{$dir}/results",
        ) === [];
}
