<?php

declare(strict_types=1);

/**
 * corpus.json access and every sizing rule derived from it: the single source for run-shard.sh (through
 * project-info.php), write-stubs.php, plan.php, compare.php and fingerprint.php. Only constants and functions
 * are defined here, requiring this file has no side effects.
 *
 * PERF_CORPUS overrides the registry path (local tests); config and lock directories live next to it in corpus/.
 * PERF_FORCE_MODE=native|cachegrind overrides every shard's mode (cachegrind is unavailable on macOS).
 */

/** The memory limit of a mode must leave this much room above a project's expected_mb (J1). */
const MEMORY_HEADROOM = 1.25;
/** t1 + four t4 processes must fit into the RAM budget of the runner (J2). */
const T4_PROCESSES = 5;
const T4_TOTAL_MB = 12_288;
/** Analysis kill limit = TIMEOUT_FACTOR x expected_seconds. */
const TIMEOUT_FACTOR = 1.5;
/** Used when neither the project nor corpus.json sets install_timeout (lint requires the global one). */
const DEFAULT_INSTALL_TIMEOUT = 180;

/**
 * @return array<string, mixed>
 */
function perfLoadCorpus(string $file): array
{
    $corpus = json_decode((string) @file_get_contents($file), true);
    if (!is_array($corpus) || !isset($corpus['projects']) || !is_array($corpus['projects'])) {
        fwrite(STDERR, "cannot read corpus {$file}\n");
        exit(2);
    }

    return $corpus;
}

function perfCorpusFile(string $default): string
{
    $override = getenv('PERF_CORPUS');

    return $override !== false && $override !== '' ? $override : $default;
}

/**
 * Enabled projects in registry order, or exactly the named ones (disabled included) when $only is not empty.
 *
 * @param array<string, mixed> $corpus
 * @param list<string> $only
 * @return array<string, array<string, mixed>>
 */
function perfSelectProjects(array $corpus, array $only): array
{
    $unknown = array_diff($only, array_keys($corpus['projects']));
    if ($unknown !== []) {
        fwrite(STDERR, 'unknown project(s): ' . implode(', ', $unknown) . "\n");
        exit(2);
    }

    $selected = [];
    foreach ($corpus['projects'] as $name => $project) {
        // Disabled projects are skipped unless named explicitly (dispatch sizing runs).
        $wanted = $only === [] ? !isset($project['disabled']) : in_array($name, $only, true);
        if ($wanted) {
            $selected[$name] = $project;
        }
    }

    return $selected;
}

/**
 * Projects run-shard.sh measures: the named ones when $only is not empty, else the enabled members of the shard.
 *
 * @param array<string, mixed> $corpus
 * @param list<string> $only
 * @return array<string, array<string, mixed>>
 */
function perfShardProjects(array $corpus, string $shard, array $only): array
{
    if ($only !== []) {
        return perfSelectProjects($corpus, $only);
    }

    return array_filter(
        perfSelectProjects($corpus, []),
        static fn(array $project): bool => in_array($shard, $project['shards'] ?? [], true),
    );
}

/**
 * @param array<string, mixed> $project
 */
function perfShardId(array $project): string
{
    return (string) ($project['shards'][0] ?? '');
}

/**
 * @param array<string, mixed> $corpus
 * @param array<string, mixed> $project
 */
function perfProjectMode(array $corpus, array $project): string
{
    return perfShardMode($corpus, perfShardId($project));
}

/**
 * @param array<string, mixed> $corpus
 */
function perfShardMode(array $corpus, string $shard): string
{
    $forced = getenv('PERF_FORCE_MODE');
    if ($forced === 'native' || $forced === 'cachegrind') {
        return $forced;
    }

    return (string) ($corpus['shards'][$shard]['mode'] ?? 'native');
}

/**
 * Analysis kill limit in seconds: ceil(1.5 x expected_seconds).
 *
 * @param array<string, mixed> $project
 */
function perfAnalysisTimeout(array $project): int
{
    return (int) ceil(TIMEOUT_FACTOR * (float) ($project['expected_seconds'] ?? 0));
}

/**
 * Install (clone + composer) kill limit in seconds: the project's own `install_timeout`, else the global one.
 *
 * @param array<string, mixed> $corpus
 * @param array<string, mixed> $project
 */
function perfInstallTimeout(array $corpus, array $project): int
{
    return (int) ($project['install_timeout'] ?? $corpus['install_timeout'] ?? DEFAULT_INSTALL_TIMEOUT);
}

/**
 * t4 (4 threads, push/dispatch only) runs for native projects whose five processes at 1.25 x expected_mb fit the
 * RAM budget (J2). A project without expected_mb never qualifies.
 *
 * @param array<string, mixed> $corpus
 * @param array<string, mixed> $project
 */
function perfT4Eligible(array $corpus, array $project): bool
{
    return perfProjectMode($corpus, $project) === 'native'
        && T4_PROCESSES * MEMORY_HEADROOM * (float) ($project['expected_mb'] ?? INF) <= T4_TOTAL_MB;
}

/**
 * `--memory-limit` of the t4 run: 1.25 x expected_mb per process, in MiB.
 *
 * @param array<string, mixed> $project
 */
function perfT4MemoryLimitMb(array $project): int
{
    return (int) ceil(MEMORY_HEADROOM * (float) ($project['expected_mb'] ?? 0));
}

/**
 * Case-insensitive, whitespace-collapsed CPU model: corpus.json holds the strings as recorded by various runners.
 */
function perfNormalizeCpu(string $model): string
{
    return strtolower(trim((string) preg_replace('/\s+/', ' ', $model)));
}

/**
 * @param array<string, mixed> $corpus
 */
function perfCpuMeasured(array $corpus, string $model): bool
{
    $known = array_map(
        static fn(mixed $cpu): string => perfNormalizeCpu((string) $cpu),
        (array) ($corpus['measured_cpus'] ?? []),
    );

    return in_array(perfNormalizeCpu($model), $known, true);
}

/**
 * @param array<string, mixed> $project
 */
function perfConfigDir(string $corpusFile, string $name, array $project): string
{
    return dirname($corpusFile) . '/corpus/' . ($project['config'] ?? $name);
}

/**
 * Slices reuse their parent's composer.lock through "lock"; default is the config directory.
 *
 * @param array<string, mixed> $project
 */
function perfLockDir(string $corpusFile, string $name, array $project): string
{
    return dirname($corpusFile) . '/corpus/' . ($project['lock'] ?? $project['config'] ?? $name);
}
