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

function fileSha256(string $path): ?string
{
    $hash = is_file($path) ? hash_file('sha256', $path) : false;

    return $hash === false ? null : $hash;
}

/**
 * Problems that make a result unusable as a stored head result of a bundle.
 *
 * @param array<mixed> $result decoded <project>.json
 * @param array<mixed> $expected expected.json entry of the project
 * @return list<string>
 */
function storableResultProblems(string $dir, string $project, array $result, array $expected, string $sha): array
{
    $problems = [];
    $wanted = [
        'project' => $project,
        'psalm_sha' => $sha,
        'shard' => $expected['shard'] ?? null,
        'mode' => $expected['mode'] ?? null,
    ];
    foreach ($wanted as $key => $want) {
        if (($result[$key] ?? null) !== $want) {
            $problems[] = "{$key} is " . json_encode($result[$key] ?? null) . ", expected " . json_encode($want);
        }
    }

    $status = $result['status'] ?? null;
    if (!in_array($status, BUNDLE_STORED_STATUSES, true)) {
        return [...$problems, 'status ' . json_encode($status) . ' cannot be stored (setup-failed or unknown)'];
    }

    if ($status !== 'ok') {
        if (empty($result['reason']) && empty($result['log_tail'])) {
            $problems[] = "status {$status} has neither reason nor log_tail";
        }
    } else {
        array_push($problems, ...okResultProblems($dir, $result));
    }

    $fingerprint = $result['env']['fingerprint'] ?? null;
    if (in_array($status, BUNDLE_REUSABLE_STATUSES, true) && !isSha256($fingerprint)) {
        $problems[] = 'env.fingerprint is missing';
    }

    return $problems;
}

/**
 * @param array<mixed> $result
 * @return list<string>
 */
function okResultProblems(string $dir, array $result): array
{
    $problems = [];
    $metrics = $result['metrics'] ?? [];
    if (!is_numeric($metrics['psalm_mb'] ?? null)) {
        $problems[] = 'metrics.psalm_mb is missing';
    }

    if (($result['mode'] ?? null) === 'cachegrind' && !is_numeric($metrics['ir'] ?? null)) {
        $problems[] = 'metrics.ir is missing';
    }

    $issues = is_string($result['issues_file'] ?? null) ? basename($result['issues_file']) : '';
    if ($issues === '' || !is_file($dir . '/' . $issues)) {
        $problems[] = 'issues file is missing';
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

    $lockSha = fileSha256($lockFile);
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

        foreach (storableResultProblems($headDir, $name, $result, $entry, (string) $sha) as $problem) {
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
        $manifest['result_sha256'][$name] = fileSha256($target);
        $manifest['env_fingerprints'][$name] = $result['env']['fingerprint'] ?? null;
        $manifest['soft'][$name] = $result['env']['soft'] ?? (object) [];

        $issues = is_string($result['issues_file'] ?? null) ? basename($result['issues_file']) : '';
        if ($issues !== '' && is_file("{$headDir}/{$issues}")) {
            $target = "{$outDir}/results/{$issues}";
            copy("{$headDir}/{$issues}", $target) || throw new RuntimeException("cannot copy {$issues}");
            $manifest['issues_sha256'][$name] = fileSha256($target);
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

    if (fileSha256("{$dir}/psalm-composer.lock") !== ($manifest['lock_sha256'] ?? null)) {
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
 * @param array<mixed> $manifest
 * @return list<string>
 */
function storedResultProblems(string $dir, array $manifest, string $sha, string $name, mixed $fingerprint): array
{
    $file = "{$dir}/results/{$name}.json";
    if (!isSafeName($name) || !is_file($file)) {
        return ['result file is missing'];
    }

    if (fileSha256($file) !== ($manifest['result_sha256'][$name] ?? null)) {
        return ['result file does not match manifest result_sha256'];
    }

    $result = json_decode((string) file_get_contents($file), true);
    if (!is_array($result)) {
        return ['result file is not valid JSON'];
    }

    $problems = [];
    if (($result['project'] ?? null) !== $name || ($result['psalm_sha'] ?? null) !== $sha) {
        $problems[] = 'project or psalm_sha of the result differs from the manifest';
    }

    if (!in_array($result['status'] ?? null, BUNDLE_STORED_STATUSES, true)) {
        $problems[] = 'status ' . json_encode($result['status'] ?? null) . ' is not storable';
    }

    if (($result['env']['fingerprint'] ?? null) !== $fingerprint) {
        $problems[] = 'env fingerprint differs from the manifest';
    }

    if (($result['status'] ?? null) === 'ok') {
        $issues = is_string($result['issues_file'] ?? null) ? basename($result['issues_file']) : '';
        $expectedHash = $manifest['issues_sha256'][$name] ?? null;
        if ($issues === '' || $expectedHash === null || fileSha256("{$dir}/results/{$issues}") !== $expectedHash) {
            $problems[] = 'issues file is missing or does not match manifest issues_sha256';
        }
    }

    return $problems;
}

/**
 * Reuse rule: a stored ok/crash/timeout result measured in an identical environment.
 *
 * @param array<mixed> $manifest validated manifest
 */
function bundleResultReusable(string $dir, array $manifest, string $sha, string $name, string $headEnvFingerprint): bool
{
    if (($manifest['env_fingerprints'][$name] ?? null) !== $headEnvFingerprint) {
        return false;
    }

    $file = "{$dir}/results/{$name}.json";
    $result = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($result)
        && ($result['psalm_sha'] ?? null) === $sha
        && in_array($result['status'] ?? null, BUNDLE_REUSABLE_STATUSES, true);
}
