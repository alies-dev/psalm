<?php

declare(strict_types=1);

/**
 * Derive the composer.lock a perf run installs Psalm from.
 *
 * Usage: php resolve-lock.php --start <lock path|-> --composer-json <path> --mode strict|fallback
 *                             --out <lock path> --info <json path>
 *
 * Procedure: reuse the starting lock when it is fresh and installable; else `composer update --minimal-changes
 * --no-install` from it, accepted only when every package present in both locks keeps its version and source
 * reference unless the new constraints exclude the old version; else strict mode fails, fallback mode resolves
 * from scratch. No starting lock resolves from scratch. COMPOSER_ROOT_VERSION and the composer cache are
 * inherited from the environment. Needs the composer phar (Composer\Semver is loaded from it).
 *
 * info: {"lock_source": "reused|minimal|fresh|failed", "reason": "...",
 *        "changed": [{"name", "from", "to", "from_ref", "to_ref"}]}
 * Exit: 0 a lock was written to --out, 3 no lock (lock_source failed), 2 usage or environment error.
 */

require __DIR__ . '/lib/cli.php';
require __DIR__ . '/lib/lock.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "resolve-lock: {$message}\n");
    exit(2);
};

try {
    $o = parseOptions(array_slice($argv, 1), ['start', 'composer-json', 'mode', 'out', 'info']);
    requireOptions($o, ['start', 'composer-json', 'mode', 'out', 'info']);
    if (!in_array($o['mode'], ['strict', 'fallback'], true)) {
        $fail('--mode must be strict or fallback');
    }

    $composerJson = is_file($o['composer-json']) ? file_get_contents($o['composer-json']) : false;
    if ($composerJson === false || !is_array(json_decode($composerJson, true))) {
        $fail("{$o['composer-json']} is missing or not valid JSON");
    }

    $startLock = null;
    if ($o['start'] !== '-') {
        $startLock = is_file($o['start']) ? file_get_contents($o['start']) : false;
        if ($startLock === false) {
            $fail("starting lock {$o['start']} cannot be read (use - for none)");
        }
    }

    $result = resolveLock($startLock, $composerJson, $o['mode'] === 'strict');
    writeJsonFile($o['info'], $result['info']);
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}

fwrite(STDERR, "resolve-lock: {$result['info']['lock_source']}: {$result['info']['reason']}\n");
if ($result['lock'] === null) {
    if (is_file($o['out'])) {
        unlink($o['out']);
    }

    exit(3);
}

if (file_put_contents($o['out'], $result['lock']) === false) {
    $fail("cannot write {$o['out']}");
}

exit(0);
