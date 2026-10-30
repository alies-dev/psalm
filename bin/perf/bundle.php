<?php

declare(strict_types=1);

/**
 * Build or validate the base bundle (perf-base-<branch>-<sha> cache content).
 *
 * Usage:
 *   php bundle.php build --head-dir <dir> --expected <expected.json> --lock <file> --lock-info <locks-info.json>
 *                        --harness-sha <sha> --out <bundle dir>
 *   php bundle.php validate --dir <dir> --sha <sha>
 *
 * build: exit 0 and bundle written when every expected PR-tier head result is present and storable, exit 3
 *        (reasons on stderr, nothing written) otherwise. --lock-info is the whole locks-info.json; its "head"
 *        entry is used.
 * validate: exit 0 and the manifest on stdout, exit 1 (problems on stderr) when the bundle is not trustworthy.
 * Exit 2: usage error.
 */

require __DIR__ . '/lib/cli.php';
require __DIR__ . '/lib/bundle.php';

$fail = static function (string $message, int $code): never {
    fwrite(STDERR, "bundle: {$message}\n");
    exit($code);
};

$command = $argv[1] ?? '';

try {
    if ($command === 'build') {
        $o = parseOptions(
            array_slice($argv, 2),
            ['head-dir', 'expected', 'lock', 'lock-info', 'harness-sha', 'out'],
        );
        requireOptions($o, ['head-dir', 'expected', 'lock', 'lock-info', 'harness-sha', 'out']);
        $locksInfo = readJsonFile($o['lock-info']);
        $problems = buildBundle(
            $o['head-dir'],
            readJsonFile($o['expected']),
            $o['lock'],
            is_array($locksInfo['head'] ?? null) ? $locksInfo['head'] : [],
            $o['harness-sha'],
            $o['out'],
        );
        if ($problems !== []) {
            $fail("not eligible:\n  " . implode("\n  ", $problems), 3);
        }

        exit(0);
    }

    if ($command === 'validate') {
        $o = parseOptions(array_slice($argv, 2), ['dir', 'sha']);
        requireOptions($o, ['dir', 'sha']);
        [$manifest, $problems] = validateBundle($o['dir'], $o['sha']);
        if ($problems !== [] || $manifest === null) {
            $fail("invalid bundle:\n  " . implode("\n  ", $problems), 1);
        }

        echo json_encode($manifest, JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
} catch (RuntimeException $e) {
    $fail($e->getMessage(), 2);
}

$fail('usage: bundle.php build|validate ... (see the header of this file)', 2);
