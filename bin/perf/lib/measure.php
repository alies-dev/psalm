<?php

declare(strict_types=1);

/**
 * Environment access for the measuring side (fingerprint.php, project-info.php): the php flags and the CPU model.
 * The registry and its sizing rules live in corpus.php, which the entry scripts load alongside.
 *
 * PERF_FORCE_MODE=native|cachegrind overrides every shard's mode (cachegrind is unavailable on macOS).
 */

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
