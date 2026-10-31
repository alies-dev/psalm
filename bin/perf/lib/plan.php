<?php

declare(strict_types=1);

const INSTALL_SECONDS = 180;
const RUNS_PER_PROJECT = 4;
const JOB_SLACK_MINUTES = 5;

/**
 * @param list<string> $args
 */
function runPlan(array $args): int
{
    $options = parseOptions($args, ['corpus']);
    requireOptions($options, ['corpus']);
    echo json_encode(buildPlan(readJsonFile($options['corpus'])), JSON_UNESCAPED_SLASHES), "\n";

    return 0;
}

/**
 * "12G" / "512M" to MiB.
 */
function memoryLimitMb(string $limit): int
{
    return (int) $limit * (str_ends_with(strtoupper($limit), 'G') ? 1024 : 1);
}

/**
 * Runner budget: per project one install plus up to four analyses (base, head and a reversed re-run).
 *
 * @param list<string> $members
 * @param array<mixed> $projects
 */
function jobMinutes(array $members, array $projects): int
{
    $seconds = 0;
    foreach ($members as $name) {
        $seconds += INSTALL_SECONDS + RUNS_PER_PROJECT * (int) ($projects[$name]['timeout'] ?? 0);
    }

    return (int) ceil($seconds / 60) + JOB_SLACK_MINUTES;
}

/**
 * The plan consumed by the workflow: matrix jobs plus what compare needs to judge crashes.
 *
 * @param array<mixed> $corpus
 * @return array<string, mixed>
 */
function buildPlan(array $corpus): array
{
    $jobs = [];
    foreach ($corpus['jobs'] as $job => $members) {
        $jobs[] = [
            'job' => (string) $job,
            'projects' => $members,
            'timeout_minutes' => jobMinutes($members, $corpus['projects']),
        ];
    }

    return [
        'memory_limit_mb' => memoryLimitMb($corpus['memory_limit']),
        'timeouts' => array_map(static fn(array $p): int => $p['timeout'], $corpus['projects']),
        'jobs' => $jobs,
    ];
}
