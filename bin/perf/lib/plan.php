<?php

declare(strict_types=1);

const INSTALL_SECONDS = 180;
const RUNS_PER_PROJECT = 4;
const JOB_SLACK_MINUTES = 5;
const MAX_JOB_MINUTES = 60;
const PROJECT_KEYS = ['repo', 'ref', 'tag', 'config', 'composer', 'setup', 'timeout', 'exercises'];

/**
 * @param list<string> $args
 */
function runPlan(array $args): int
{
    $options = parseOptions($args, ['corpus']);
    requireOptions($options, ['corpus']);
    $corpus = readJsonFile($options['corpus']);
    $problems = corpusProblems($corpus, dirname($options['corpus']));
    if ($problems !== []) {
        fwrite(STDERR, "perf plan: invalid corpus\n  " . implode("\n  ", $problems) . "\n");

        return 2;
    }

    echo json_encode(buildPlan($corpus), JSON_UNESCAPED_SLASHES), "\n";

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
 * Per-project problems of the corpus, empty when it is usable.
 *
 * @param array<mixed> $corpus
 * @return list<string>
 */
function corpusProblems(array $corpus, string $corpusDir): array
{
    $problems = [];
    $phpIni = $corpus['php_ini'] ?? null;
    if (!is_string($corpus['php'] ?? null)) {
        $problems[] = "'php' must be a string";
    }

    if (!is_array($phpIni) || $phpIni === [] || array_filter($phpIni, 'is_string') !== $phpIni) {
        $problems[] = "'php_ini' must be a non-empty object of strings";
    }

    if (preg_match('/^\d+[GM]$/', (string) ($corpus['memory_limit'] ?? '')) !== 1) {
        $problems[] = "'memory_limit' must look like 12G or 512M";
    }

    $projects = is_array($corpus['projects'] ?? null) ? $corpus['projects'] : [];
    $jobs = is_array($corpus['jobs'] ?? null) ? $corpus['jobs'] : [];
    if ($projects === [] || $jobs === []) {
        return [...$problems, "'jobs' and 'projects' must be non-empty objects"];
    }

    foreach ($projects as $name => $project) {
        $problems = [...$problems, ...projectProblems((string) $name, $project, $corpusDir)];
    }

    $jobOf = [];
    foreach ($jobs as $job => $members) {
        $members = is_array($members) ? $members : [];
        $problems = [...$problems, ...jobProblems((string) $job, $members, $projects, $corpus)];
        foreach ($members as $name) {
            $jobOf[$name][] = $job;
        }
    }

    foreach (array_diff_key($projects, $jobOf) as $name => $_) {
        $problems[] = "project {$name}: not in any job";
    }

    foreach ($jobOf as $name => $in) {
        if (count($in) > 1) {
            $problems[] = "project {$name}: in several jobs (" . implode(', ', $in) . ')';
        }
    }

    return $problems;
}

/**
 * @return list<string>
 */
function projectProblems(string $name, mixed $project, string $corpusDir): array
{
    if (!is_array($project)) {
        return ["project {$name}: must be an object"];
    }

    $problems = array_map(
        static fn(string $key): string => "project {$name}: unknown key '{$key}'",
        array_keys(array_diff_key($project, array_flip(PROJECT_KEYS))),
    );
    $timeout = $project['timeout'] ?? null;
    $config = configOf($name, $project);
    $checks = [
        'repo must be an https URL' => is_string($project['repo'] ?? null)
            && str_starts_with($project['repo'], 'https://'),
        'ref must be a 40 hex character commit sha' => is_string($project['ref'] ?? null)
            && preg_match('/^[0-9a-f]{40}$/', $project['ref']) === 1,
        'tag must be a non-empty string' => is_string($project['tag'] ?? null) && $project['tag'] !== '',
        'timeout must be an integer between 30 and 1800' => is_int($timeout) && $timeout >= 30 && $timeout <= 1800,
        'config must be a safe directory name' => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $config) === 1,
    ];
    foreach ($checks as $problem => $ok) {
        if (!$ok) {
            $problems[] = "project {$name}: {$problem}";
        }
    }

    $psalmXml = "{$corpusDir}/corpus/{$config}/psalm.xml";
    if (!is_file($psalmXml)) {
        $problems[] = "project {$name}: missing {$psalmXml}";
    }

    return $problems;
}

/**
 * @param array<mixed> $project
 */
function configOf(string $name, array $project): string
{
    return is_string($project['config'] ?? null) ? $project['config'] : $name;
}

/**
 * @param array<mixed> $members
 * @param array<mixed> $projects
 * @param array<mixed> $corpus
 * @return list<string>
 */
function jobProblems(string $job, array $members, array $projects, array $corpus): array
{
    if ($members === []) {
        return ["job {$job}: lists no projects"];
    }

    $unknown = array_filter($members, static fn(mixed $m): bool => !is_string($m) || !isset($projects[$m]));
    if ($unknown !== []) {
        return ["job {$job}: unknown projects " . implode(', ', array_map('json_encode', $unknown))];
    }

    $minutes = jobMinutes($members, $projects);
    if ($minutes > MAX_JOB_MINUTES) {
        return ["job {$job}: budget of {$minutes} minutes exceeds " . MAX_JOB_MINUTES];
    }

    return [];
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
 * @param array<mixed> $corpus validated by corpusProblems()
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
        'php' => $corpus['php'],
        'memory_limit_mb' => memoryLimitMb($corpus['memory_limit']),
        'timeouts' => array_map(static fn(array $p): int => $p['timeout'], $corpus['projects']),
        'jobs' => $jobs,
    ];
}
