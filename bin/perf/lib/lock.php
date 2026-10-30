<?php

declare(strict_types=1);

use Composer\Package\Locker;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

const COMPOSER_TIMEOUT_SECONDS = 900;
const FAILURE_TAIL_LINES = 12;
const REASON_MAX_CHARS = 1500;
const PLATFORM_PACKAGE = '/^(php(-64bit|-ipv6|-zts|-debug)?|hhvm|(ext|lib)-[a-z0-9_.-]+'
    . '|composer(-plugin-api|-runtime-api)?)$/i';

/**
 * @throws RuntimeException
 */
function findComposer(): string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        $candidate = $dir . '/composer';
        if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
            return realpath($candidate) ?: $candidate;
        }
    }

    throw new RuntimeException('composer was not found in PATH');
}

/**
 * Loads Composer\Semver and Composer\Package\Locker from the composer phar. The phar's own alias is tried first
 * because phar:// does not resolve paths without a .phar extension.
 *
 * @throws RuntimeException when composer is not a phar
 */
function loadComposerClasses(string $composer): void
{
    if (class_exists(VersionParser::class, false)) {
        return;
    }

    $autoloads = [];
    try {
        if (Phar::loadPhar($composer, 'composer.phar')) {
            $autoloads[] = 'phar://composer.phar/vendor/autoload.php';
        }
    } catch (Throwable) {
        // not a phar or a different alias; the direct path below may still work
    }

    $autoloads[] = 'phar://' . $composer . '/vendor/autoload.php';
    foreach ($autoloads as $autoload) {
        if (@is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }

    if (!class_exists(VersionParser::class) || !class_exists(Locker::class)) {
        throw new RuntimeException(
            "composer at {$composer} is not a phar, so Composer\\Semver cannot be loaded from it; "
            . 'install the composer phar (as setup-php does)',
        );
    }
}

/**
 * @param list<string> $command
 * @return array{code: int, output: string} merged stdout and stderr
 * @throws RuntimeException
 */
function runProcess(array $command, string $cwd, int $timeoutSeconds = COMPOSER_TIMEOUT_SECONDS): array
{
    $env = array_merge(getenv(), ['COMPOSER_NO_INTERACTION' => '1', 'COMPOSER_NO_AUDIT' => '1']);
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $cwd,
        $env,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start ' . $command[0]);
    }

    stream_set_blocking($pipes[1], false);
    $output = '';
    $deadline = time() + $timeoutSeconds;
    $exit = null;
    while (true) {
        $read = [$pipes[1]];
        $write = $except = null;
        stream_select($read, $write, $except, 1);
        $output .= (string) stream_get_contents($pipes[1]);

        $status = proc_get_status($process);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            $output .= (string) stream_get_contents($pipes[1]);
            break;
        }

        if (time() > $deadline) {
            proc_terminate($process, 9);
            $output .= "\n{$command[0]} killed after {$timeoutSeconds} s\n";
            break;
        }
    }

    fclose($pipes[1]);
    proc_close($process);

    return ['code' => $exit ?? 124, 'output' => $output];
}

function tailOf(string $output): string
{
    $lines = array_slice(array_values(array_filter(array_map('rtrim', explode("\n", $output)))), -FAILURE_TAIL_LINES);

    return mb_substr(implode("\n", $lines), -REASON_MAX_CHARS);
}

/**
 * @param array<mixed> $lock decoded composer.lock
 * @return array<string, array<mixed>> package name => lock entry (packages and packages-dev)
 */
function lockPackages(array $lock): array
{
    $packages = [];
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
        if (is_array($package) && isset($package['name'])) {
            $packages[(string) $package['name']] = $package;
        }
    }

    return $packages;
}

/**
 * @param array<mixed> $package
 */
function packageReference(array $package): ?string
{
    return $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
}

/**
 * @param array<mixed> $oldLock
 * @param array<mixed> $newLock
 * @return list<array{name: string, from: ?string, to: ?string, from_ref: ?string, to_ref: ?string}>
 */
function lockDiff(array $oldLock, array $newLock): array
{
    $old = lockPackages($oldLock);
    $new = lockPackages($newLock);
    $changed = [];
    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $name) {
        $from = $old[$name] ?? null;
        $to = $new[$name] ?? null;
        $entry = [
            'name' => $name,
            'from' => $from['version'] ?? null,
            'to' => $to['version'] ?? null,
            'from_ref' => $from === null ? null : packageReference($from),
            'to_ref' => $to === null ? null : packageReference($to),
        ];
        if ($entry['from'] !== $entry['to'] || $entry['from_ref'] !== $entry['to_ref']) {
            $changed[] = $entry;
        }
    }

    usort($changed, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $changed;
}

/**
 * Composer's own content-hash, so a lock is fresh exactly when `composer install` would not warn.
 *
 * @param array<mixed> $lock
 */
function isLockFresh(array $lock, string $composerJson): bool
{
    return ($lock['content-hash'] ?? null) === Locker::getContentHash($composerJson);
}

function parseConstraint(string $constraint, string $selfVersion): ?ConstraintInterface
{
    try {
        return (new VersionParser())->parseConstraints(str_replace('self.version', $selfVersion, $constraint));
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param array<mixed> $package lock entry
 */
function versionMatches(array $package, ConstraintInterface $constraint): bool
{
    $parser = new VersionParser();
    $versions = [(string) $package['version']];
    foreach ($package['extra']['branch-alias'] ?? [] as $branch => $alias) {
        if ($branch === $package['version']) {
            $versions[] = (string) $alias;
        }
    }

    foreach ($versions as $version) {
        try {
            if ($constraint->matches(new Constraint('==', $parser->normalize($version)))) {
                return true;
            }
        } catch (Throwable) {
            continue;
        }
    }

    return false;
}

/**
 * Whether the old version of a package is ruled out by the new lock or root composer.json: a root or package
 * `require` it fails, a root or package `conflict` it matches, or a root platform requirement its own platform
 * requirements cannot meet.
 *
 * @param array<mixed> $old old lock entry
 * @param array<mixed> $newLock
 * @param array<mixed> $root decoded target composer.json
 */
function isExcluded(string $name, array $old, array $newLock, array $root): bool
{
    $sources = [[
        'require' => array_merge($root['require'] ?? [], $root['require-dev'] ?? []),
        'conflict' => $root['conflict'] ?? [],
        'version' => '',
    ]];
    foreach (lockPackages($newLock) as $package) {
        $sources[] = [
            'require' => $package['require'] ?? [],
            'conflict' => $package['conflict'] ?? [],
            'version' => (string) $package['version'],
        ];
    }

    foreach ($sources as $source) {
        $needs = constraintOn($source['require'], $name, $source['version']);
        if ($needs !== null && !versionMatches($old, $needs)) {
            return true;
        }

        $forbids = constraintOn($source['conflict'], $name, $source['version']);
        if ($forbids !== null && versionMatches($old, $forbids)) {
            return true;
        }
    }

    return platformExcludes($old, $newLock);
}

/**
 * @param array<string, string> $constraints package name => constraint of a require or conflict section
 */
function constraintOn(array $constraints, string $name, string $selfVersion): ?ConstraintInterface
{
    return isset($constraints[$name]) ? parseConstraint((string) $constraints[$name], $selfVersion) : null;
}

/**
 * @param array<mixed> $old old lock entry
 * @param array<mixed> $newLock
 */
function platformExcludes(array $old, array $newLock): bool
{
    $platform = array_merge($newLock['platform'] ?? [], $newLock['platform-dev'] ?? []);
    $overrides = $newLock['platform-overrides'] ?? [];
    foreach ($old['require'] ?? [] as $dependency => $constraint) {
        if (preg_match(PLATFORM_PACKAGE, (string) $dependency) !== 1) {
            continue;
        }

        $needs = parseConstraint((string) $constraint, (string) $old['version']);
        if ($needs === null) {
            continue;
        }

        $rootConstraint = isset($platform[$dependency]) ? parseConstraint((string) $platform[$dependency], '') : null;
        if ($rootConstraint !== null && !Intervals::haveIntersections($needs, $rootConstraint)) {
            return true;
        }

        $override = isset($overrides[$dependency]) && is_string($overrides[$dependency])
            ? parseConstraint($overrides[$dependency], '')
            : null;
        if ($override !== null && !Intervals::haveIntersections($needs, $override)) {
            return true;
        }
    }

    return false;
}

/**
 * Acceptance rule (c): a package present in both locks keeps its exact version and source reference unless the
 * new constraints exclude the old version.
 *
 * @param array<mixed> $oldLock
 * @param array<mixed> $newLock
 * @param array<mixed> $root decoded target composer.json
 * @return list<string> violations; empty means the candidate is accepted
 */
function acceptanceViolations(array $oldLock, array $newLock, array $root): array
{
    $old = lockPackages($oldLock);
    $new = lockPackages($newLock);
    $violations = [];
    foreach ($old as $name => $package) {
        if (!isset($new[$name])) {
            continue;
        }

        if ($package['version'] === $new[$name]['version']) {
            if (packageReference($package) !== packageReference($new[$name])) {
                $violations[] = "upstream re-tagged {$name} {$package['version']}";
            }

            continue;
        }

        if (!isExcluded($name, $package, $newLock, $root)) {
            $violations[] = "{$name} changed {$package['version']} -> {$new[$name]['version']}"
                . " without a constraint excluding {$package['version']}";
        }
    }

    return $violations;
}

/**
 * @return string scratch directory holding the target composer.json and, if given, a candidate composer.lock
 * @throws RuntimeException
 */
function createWorkspace(string $composerJson, ?string $lockContents): string
{
    $dir = sys_get_temp_dir() . '/perf-lock-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException("cannot create {$dir}");
    }

    file_put_contents("{$dir}/composer.json", $composerJson);
    if ($lockContents !== null) {
        file_put_contents("{$dir}/composer.lock", $lockContents);
    }

    return $dir;
}

function removeWorkspace(string $dir): void
{
    runProcess(['rm', '-rf', $dir], sys_get_temp_dir(), 60);
}

/**
 * Plugins and scripts of the target never run: resolving must not execute project code.
 *
 * @return array{code: int, output: string}
 */
function composerIn(string $composer, string $dir, string ...$args): array
{
    return runProcess([$composer, ...$args, '--no-interaction', '--no-scripts', '--no-plugins'], $dir);
}

function workspaceLock(string $dir): ?string
{
    $contents = @file_get_contents("{$dir}/composer.lock");

    return $contents === false ? null : $contents;
}

/**
 * Lock procedure of the perf CI: reuse, minimal update, or (fallback mode only) a fresh resolve.
 *
 * @param string|null $startLock contents of the starting lock, null for none
 * @return array{info: array{lock_source: string, reason: string, changed: list<array<mixed>>}, lock: ?string}
 * @throws RuntimeException on environment problems (composer or its phar missing)
 */
function resolveLock(?string $startLock, string $composerJson, bool $strict): array
{
    $composer = findComposer();
    $start = $startLock === null ? null : json_decode($startLock, true);
    if ($startLock !== null && !is_array($start)) {
        throw new RuntimeException('the starting lock is not valid JSON');
    }

    if ($start === null) {
        return freshResolve($composer, $composerJson, null, 'no starting lock');
    }

    loadComposerClasses($composer);
    $reused = tryExistingLock($composer, $composerJson, $startLock, $start);
    if ($reused !== null) {
        return $reused;
    }

    $minimal = tryMinimalUpdate($composer, $composerJson, $startLock, $start);
    if (isset($minimal['lock'])) {
        return $minimal;
    }

    if ($strict) {
        return failedLock($minimal['reason']);
    }

    return freshResolve($composer, $composerJson, $start, $minimal['reason']);
}

/**
 * @param array<mixed> $start
 * @return array{info: array<mixed>, lock: string}|null
 */
function tryExistingLock(string $composer, string $composerJson, string $startLock, array $start): ?array
{
    if (!isLockFresh($start, $composerJson)) {
        return null;
    }

    $dir = createWorkspace($composerJson, $startLock);
    try {
        $dryRun = composerIn($composer, $dir, 'install', '--dry-run', '--no-dev');
    } finally {
        removeWorkspace($dir);
    }

    if ($dryRun['code'] !== 0) {
        return null;
    }

    return [
        'info' => ['lock_source' => 'reused', 'reason' => 'starting lock is fresh for composer.json', 'changed' => []],
        'lock' => $startLock,
    ];
}

/**
 * @param array<mixed> $start
 * @return array{info: array<mixed>, lock: string}|array{reason: string}
 */
function tryMinimalUpdate(string $composer, string $composerJson, string $startLock, array $start): array
{
    $dir = createWorkspace($composerJson, $startLock);
    try {
        $update = composerIn($composer, $dir, 'update', '--minimal-changes', '--no-install');
        $contents = workspaceLock($dir);
        $candidate = $contents === null ? null : json_decode($contents, true);
        if ($update['code'] !== 0 || !is_array($candidate)) {
            return ['reason' => 'composer update --minimal-changes failed: ' . tailOf($update['output'])];
        }

        if (!isLockFresh($candidate, $composerJson)) {
            return ['reason' => 'updated lock is not fresh for composer.json'];
        }

        $violations = acceptanceViolations($start, $candidate, json_decode($composerJson, true) ?: []);
        if ($violations !== []) {
            return ['reason' => 'minimal update changed packages beyond the composer.json change: '
                . implode('; ', array_slice($violations, 0, 8))];
        }

        $dryRun = composerIn($composer, $dir, 'install', '--dry-run', '--no-dev');
        if ($dryRun['code'] !== 0) {
            return ['reason' => 'dry-run install of the updated lock failed: ' . tailOf($dryRun['output'])];
        }
    } finally {
        removeWorkspace($dir);
    }

    $changed = lockDiff($start, $candidate);

    return [
        'info' => [
            'lock_source' => 'minimal',
            'reason' => count($changed) . ' package(s) changed from the starting lock',
            'changed' => $changed,
        ],
        'lock' => $contents,
    ];
}

/**
 * @param array<mixed>|null $start
 * @return array{info: array<mixed>, lock: ?string}
 */
function freshResolve(string $composer, string $composerJson, ?array $start, string $why): array
{
    $dir = createWorkspace($composerJson, null);
    try {
        $update = composerIn($composer, $dir, 'update', '--no-install');
        $contents = workspaceLock($dir);
    } finally {
        removeWorkspace($dir);
    }

    $lock = $contents === null ? null : json_decode($contents, true);
    if ($update['code'] !== 0 || !is_array($lock)) {
        return failedLock("fresh resolve failed ({$why}): " . tailOf($update['output']));
    }

    return [
        'info' => [
            'lock_source' => 'fresh',
            'reason' => "resolved from scratch: {$why}",
            'changed' => $start === null ? [] : lockDiff($start, $lock),
        ],
        'lock' => $contents,
    ];
}

/**
 * @return array{info: array<mixed>, lock: null}
 */
function failedLock(string $reason): array
{
    return ['info' => ['lock_source' => 'failed', 'reason' => $reason, 'changed' => []], 'lock' => null];
}
