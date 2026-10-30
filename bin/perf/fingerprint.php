<?php

declare(strict_types=1);

/**
 * Environment (hard) and soft fingerprints of the measurement setup.
 *
 * Usage: php fingerprint.php --corpus <corpus.json> --projects <csv> [--valgrind-version <dpkg version>]
 * Prints {"env": {project: sha256}, "soft": {project: {libs, image_os, image_version, cpu}}}.
 * Re-executes itself under the flags of php-flags.txt so ini_get_all() sees what Psalm runs with.
 * Exit: 0 ok, 2 usage/corpus error.
 */

require __DIR__ . '/lib/measure.php';
require __DIR__ . '/lib/fingerprint.php';

perfReexecWithFlags(__DIR__ . '/php-flags.txt', __FILE__, $argv);

$options = getopt('', ['corpus:', 'projects:', 'valgrind-version:']);
if (!isset($options['corpus'], $options['projects'])
    || !is_string($options['corpus'])
    || !is_string($options['projects'])
) {
    fwrite(STDERR, "usage: php fingerprint.php --corpus <corpus.json> --projects <csv> [--valgrind-version <v>]\n");
    exit(2);
}

$corpusFile = perfCorpusFile($options['corpus']);
$corpus = perfLoadCorpus($corpusFile);
$names = array_values(array_filter(explode(',', $options['projects']), static fn(string $p): bool => $p !== ''));
$valgrindVersion = is_string($options['valgrind-version'] ?? null) ? $options['valgrind-version'] : 'none';

$extensions = perfExtensionFiles();
$soft = [
    'libs' => perfLibraryHashes([PHP_BINARY, ...array_values($extensions)]),
    'image_os' => getenv('ImageOS') ?: null,
    'image_version' => getenv('ImageVersion') ?: null,
    'cpu' => perfCpuModel(),
];

$env = [];
$softByProject = [];
foreach (perfSelectProjects($corpus, $names) as $name => $project) {
    $env[$name] = perfEnvFingerprint(__DIR__, $corpusFile, $corpus, $name, $project, $extensions, $valgrindVersion);
    $softByProject[$name] = $soft;
}

echo json_encode(
    ['env' => (object) $env, 'soft' => (object) $softByProject],
    JSON_UNESCAPED_SLASHES,
), "\n";
