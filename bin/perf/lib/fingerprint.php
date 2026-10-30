<?php

declare(strict_types=1);

/**
 * Environment fingerprints for fingerprint.php (see bin/perf/README.md).
 *
 * ENV is hard: base and head must match or their Ir/MB are not comparable. SOFT is recorded for the report only.
 */

function perfFileSha(string $path): string
{
    return is_file($path) ? (string) hash_file('sha256', $path) : 'absent';
}

/**
 * Loaded shared extensions (including zend_extensions such as opcache) by name.
 *
 * @return array<string, string>
 */
function perfExtensionFiles(): array
{
    $dir = (string) ini_get('extension_dir');
    $files = [];
    foreach (get_loaded_extensions() as $extension) {
        $name = str_replace('zend ', '', strtolower($extension));
        if (is_file("{$dir}/{$name}.so")) {
            $files[$name] = (string) realpath("{$dir}/{$name}.so");
        }
    }

    ksort($files);

    return $files;
}

/**
 * Shared libraries of the `ldd` closure of the given binaries. Linux only.
 *
 * @param list<string> $binaries
 * @return array<string, string> real path => sha256
 */
function perfLibraryHashes(array $binaries): array
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return [];
    }

    $libs = [];
    foreach ($binaries as $binary) {
        $output = (string) shell_exec('ldd ' . escapeshellarg($binary) . ' 2>/dev/null');
        if (preg_match_all('~(?:=>\s*|^\s*)(/\S+)\s+\(0x~m', $output, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $lib) {
            $real = realpath($lib);
            if ($real !== false) {
                $libs[$real] = perfFileSha($real);
            }
        }
    }

    ksort($libs);

    return $libs;
}

/**
 * @param array<string, mixed> $corpus
 * @param array<string, mixed> $project
 * @param array<string, string> $extensions
 */
function perfEnvFingerprint(
    string $harnessDir,
    string $corpusFile,
    array $corpus,
    string $name,
    array $project,
    array $extensions,
    string $valgrindVersion,
): string {
    $mode = perfProjectMode($corpus, $project);
    $fields = [];
    foreach (['repo', 'ref', 'tag', 'composer', 'setup', 'config', 'lock'] as $key) {
        $fields[$key] = $project[$key] ?? null;
    }

    $harness = [];
    foreach (['run-shard.sh', 'normalize-issues.php', 'php-flags.txt'] as $file) {
        $harness[$file] = perfFileSha("{$harnessDir}/{$file}");
    }

    $ini = ini_get_all(null, false);
    ksort($ini);

    $parts = [
        'harness' => $harness,
        'project' => $fields,
        'mode' => $mode,
        'php_setting' => $corpus['php'] ?? null,
        'memory_limit' => $corpus['memory_limit'][$mode] ?? null,
        'psalm.xml' => perfFileSha(perfConfigDir($corpusFile, $name, $project) . '/psalm.xml'),
        'composer.lock' => perfFileSha(perfLockDir($corpusFile, $name, $project) . '/composer.lock'),
        'php' => PHP_VERSION,
        'php_binary' => perfFileSha(PHP_BINARY),
        'extensions' => array_map('perfFileSha', $extensions),
        'ini' => $ini,
        'valgrind' => $mode === 'cachegrind' ? $valgrindVersion : null,
    ];

    return hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

/**
 * Re-execute the current script under the flags from php-flags.txt unless already done.
 *
 * @param list<string> $argv
 */
function perfReexecWithFlags(string $flagsFile, string $script, array $argv): void
{
    if (getenv('PERF_FLAGS_APPLIED') === '1') {
        return;
    }

    $command = [PHP_BINARY, ...perfPhpFlags($flagsFile), $script, ...array_slice($argv, 1)];
    $process = proc_open(
        $command,
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        null,
        ['PERF_FLAGS_APPLIED' => '1'] + getenv(),
    );
    exit(is_resource($process) ? proc_close($process) : 1);
}
