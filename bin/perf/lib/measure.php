<?php

declare(strict_types=1);

/**
 * corpus.json access shared by fingerprint.php and write-stubs.php.
 *
 * PERF_CORPUS overrides the registry path (local tests); config and lock directories live next to it in corpus/.
 * PERF_FORCE_MODE=native|cachegrind overrides every shard's mode (cachegrind is unavailable on macOS).
 */

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
    $forced = getenv('PERF_FORCE_MODE');
    if ($forced === 'native' || $forced === 'cachegrind') {
        return $forced;
    }

    return (string) ($corpus['shards'][perfShardId($project)]['mode'] ?? 'native');
}

/**
 * Analysis kill limit: ceil(1.5 x expected_seconds).
 *
 * @param array<string, mixed> $project
 */
function perfProjectTimeout(array $project): int
{
    return (int) ceil(1.5 * (float) ($project['expected_seconds'] ?? 600));
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

/**
 * @return list<string>
 */
function perfPhpFlags(string $flagsFile): array
{
    $flags = [];
    foreach (file($flagsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        array_push($flags, ...preg_split('/\s+/', trim($line)));
    }

    return $flags;
}

function perfCpuModel(): string
{
    $model = '';
    if (is_readable('/proc/cpuinfo')) {
        foreach (file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'model name')) {
                $model = trim(substr($line, (int) strpos($line, ':') + 1));
                break;
            }
        }
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $model = trim((string) @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null'));
    }

    return $model === '' ? 'unknown' : $model;
}
