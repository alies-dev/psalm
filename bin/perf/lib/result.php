<?php

declare(strict_types=1);

/**
 * Result JSON helpers for write-stubs.php (run-shard.sh writes real results itself).
 */

const PERF_LOG_TAIL_LINES = 50;
const PERF_LOG_LINE_CHARS = 500;
const PERF_FATAL_LINES = 10;

/**
 * Last lines of a log, preceded by fatal-error lines so valgrind noise cannot push them out.
 */
function perfLogTail(string $file): ?string
{
    if (!is_file($file)) {
        return null;
    }

    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return null;
    }

    fseek($handle, -262_144, SEEK_END);
    $lines = explode("\n", rtrim((string) stream_get_contents($handle)));
    fclose($handle);

    $fatal = array_slice(
        array_filter($lines, static fn(string $l): bool => preg_match('/Fatal|Allowed memory|^PHP /', $l) === 1),
        -PERF_FATAL_LINES,
    );
    $tail = array_slice($lines, -(PERF_LOG_TAIL_LINES - count($fatal)));
    $merged = array_slice(array_values(array_unique([...$fatal, ...$tail])), 0, PERF_LOG_TAIL_LINES);
    $text = implode("\n", array_map(static fn(string $l): string => mb_substr($l, 0, PERF_LOG_LINE_CHARS), $merged));

    return trim($text) === '' ? null : $text;
}

/**
 * Status of a shard step that died before writing <project>.json: derived from its .started marker.
 *
 * @return array{status: string, reason: string, elapsed_analysis: ?float}
 */
function perfStubOutcome(string $startedFile, int $timeout, int $now): array
{
    $started = is_file($startedFile) ? (int) trim((string) file_get_contents($startedFile)) : 0;
    if ($started <= 0) {
        return [
            'status' => 'setup-failed',
            'reason' => 'no result: the job stopped before analysis started',
            'elapsed_analysis' => null,
        ];
    }

    $elapsed = max(0, $now - $started);
    if ($elapsed >= $timeout) {
        return [
            'status' => 'timeout',
            'reason' => "analysis exceeded {$timeout}s (job interrupted after {$elapsed}s)",
            'elapsed_analysis' => (float) $elapsed,
        ];
    }

    return [
        'status' => 'inconclusive',
        'reason' => "interrupted (runner/step) {$elapsed}s into a {$timeout}s analysis",
        'elapsed_analysis' => (float) $elapsed,
    ];
}

/**
 * @param array{status: string, reason: string, elapsed_analysis: ?float} $outcome
 * @return array<string, mixed>
 */
function perfStubResult(
    string $name,
    string $shard,
    string $mode,
    string $sha,
    array $outcome,
    ?string $logTail,
    ?string $lockSha,
): array {
    return [
        'schema' => 2,
        'project' => $name,
        'shard' => $shard,
        'mode' => $mode,
        'psalm_sha' => $sha,
        'status' => $outcome['status'],
        'reason' => $outcome['reason'],
        'stub' => true,
        'metrics' => [
            'ir' => null, 'psalm_mb' => null, 'wall_t1' => null, 'cpu_t1' => null, 'rss_t1_kb' => null,
            'wall_t4' => null, 'rss_t4_kb' => null, 'psalm_mb_t4' => null,
        ],
        'issue_count' => null,
        'issues_file' => null,
        'exit_code' => null,
        'signal' => null,
        'max_rss_kb' => null,
        'oom_killed' => null,
        'lock_sha256' => $lockSha,
        'env' => ['fingerprint' => null],
        'elapsed_analysis' => $outcome['elapsed_analysis'],
        'elapsed_install' => null,
        'log_tail' => $logTail,
    ];
}
