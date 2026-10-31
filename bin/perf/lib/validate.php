<?php

declare(strict_types=1);

/**
 * The one status-aware validation of a per-project result, shared by compare.php and bundle.php.
 */

const PERF_STATUSES = ['ok', 'crash', 'timeout', 'setup-failed', 'inconclusive'];
const PERF_REQUIRED_METRICS = [
    'cachegrind' => ['ir', 'psalm_mb'],
    'native' => ['psalm_mb', 'wall_t1'],
];

/**
 * Structural problems of one result against what is expected of it. Empty list = valid.
 *
 *  - always: psalm_sha equals the expected sha, lock_sha256 is present and equals the expected lock sha256,
 *    shard (when the project entry has one) and mode match, the status is known;
 *  - ok: the required metrics (cachegrind: ir, psalm_mb; native: psalm_mb, wall_t1) are numbers > 0, the environment
 *    fingerprint is present and the issues file in $dir is a readable JSON list;
 *  - crash/timeout: a numeric elapsed_analysis plus a log_tail or a reason;
 *  - setup-failed/inconclusive: a reason.
 *
 * @param array<string, mixed> $result decoded <project>.json
 * @param array{sha?: mixed, lock_sha256?: mixed} $expectedRev expected psalm sha and lock sha256
 * @param array<string, mixed> $project project entry of expected.json
 * @param string $mode cachegrind|native
 * @param string|null $dir directory holding the result's issues file
 * @return list<string>
 */
function perfResultProblems(array $result, array $expectedRev, array $project, string $mode, ?string $dir = null): array
{
    $problems = [];
    $check = static function (bool $ok, string $message) use (&$problems): void {
        if (!$ok) {
            $problems[] = $message;
        }
    };
    $nonEmpty = static fn(mixed $v): bool => is_string($v) && trim($v) !== '';

    $expectedSha = $expectedRev['sha'] ?? null;
    $check(
        $nonEmpty($expectedSha) && ($result['psalm_sha'] ?? null) === $expectedSha,
        'psalm_sha differs from the expected sha',
    );

    $expectedLock = $expectedRev['lock_sha256'] ?? null;
    $check(
        $nonEmpty($expectedLock) && ($result['lock_sha256'] ?? null) === $expectedLock,
        'Psalm lock differs from (or is missing for) the expected lock',
    );

    if (isset($project['shard'])) {
        $check(($result['shard'] ?? null) === $project['shard'], 'shard differs');
    }

    $check(($result['mode'] ?? null) === $mode, 'mode differs');

    $status = $result['status'] ?? null;
    $check(in_array($status, PERF_STATUSES, true), 'unknown status');
    if ($status === 'ok') {
        foreach (PERF_REQUIRED_METRICS[$mode] ?? [] as $metric) {
            $value = $result['metrics'][$metric] ?? null;
            $check(is_numeric($value) && $value > 0, "metric {$metric} missing");
        }

        $check($nonEmpty($result['env']['fingerprint'] ?? null), 'env fingerprint missing');
        $check(perfLoadIssues($dir, $result['issues_file'] ?? null) !== null, 'issues file missing or invalid');
    } elseif ($status === 'crash' || $status === 'timeout') {
        $check(is_numeric($result['elapsed_analysis'] ?? null), 'elapsed_analysis missing');
        $check(
            $nonEmpty($result['log_tail'] ?? null) || $nonEmpty($result['reason'] ?? null),
            'neither log_tail nor reason',
        );
    } elseif (in_array($status, PERF_STATUSES, true)) {
        $check($nonEmpty($result['reason'] ?? null), 'reason missing');
    }

    return $problems;
}

/**
 * The issues list of a result: the JSON list in $dir named by the result's issues_file (basename only), or null
 * when the file is missing, unreadable or not a JSON list.
 *
 * @return list<array<string, mixed>>|null
 */
function perfLoadIssues(?string $dir, mixed $issuesFile): ?array
{
    $name = is_string($issuesFile) ? basename($issuesFile) : '';
    if ($dir === null || $name === '') {
        return null;
    }

    $raw = @file_get_contents(rtrim($dir, '/') . '/' . $name);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) && array_is_list($data) ? $data : null;
}
