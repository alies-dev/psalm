<?php

declare(strict_types=1);

/**
 * Turn a Psalm JSON report into the normalized, deterministic issue list that
 * run-shard.sh stores and compare.php diffs.
 *
 * Usage: php normalize-issues.php <report.json> <project root (absolute)> <out.issues.json>
 * Prints the issue count. Exit: 0 ok, 1 unreadable report, 2 usage.
 *
 * - paths are relative to the project root (also inside messages)
 * - digit runs of >= 9 characters in messages become '#': runtime values such as
 *   REQUEST_TIME embedded in $_SERVER shapes would otherwise differ on every run
 * - sorted by file, line, column, type, message, severity
 * - severity is kept: the issue diff identity includes it
 */

if ($argc !== 4) {
    fwrite(STDERR, "usage: php normalize-issues.php <report.json> <project root> <out.issues.json>\n");
    exit(2);
}

[, $reportFile, $root, $outFile] = $argv;

ini_set('memory_limit', '-1');

$raw = @file_get_contents($reportFile);
$report = $raw === false ? null : json_decode($raw, true);
unset($raw);
if (!is_array($report) || !array_is_list($report)) {
    fwrite(STDERR, "normalize-issues: {$reportFile} is not a Psalm JSON report\n");
    exit(1);
}

$roots = array_values(array_unique(array_filter([
    rtrim($root, '/'),
    ($real = realpath($root)) === false ? '' : rtrim($real, '/'),
])));

/**
 * @param list<string> $roots
 */
$relativePath = static function (string $path, array $roots): ?string {
    foreach ($roots as $root) {
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }
    }

    return null;
};

$issues = [];
/** @var array<string, mixed> $issue */
foreach ($report as $issue) {
    $file = $relativePath((string) ($issue['file_path'] ?? ''), $roots) ?? (string) ($issue['file_name'] ?? '');
    $message = (string) ($issue['message'] ?? '');
    foreach ($roots as $root) {
        $message = str_replace($root . '/', '', $message);
    }

    $issues[] = [
        'type' => (string) ($issue['type'] ?? '?'),
        'severity' => (string) ($issue['severity'] ?? ''),
        'file' => $file,
        'line_from' => (int) ($issue['line_from'] ?? 0),
        'line_to' => (int) ($issue['line_to'] ?? 0),
        'column_from' => (int) ($issue['column_from'] ?? 0),
        'column_to' => (int) ($issue['column_to'] ?? 0),
        'message' => (string) preg_replace('/\d{9,}/', '#', $message),
    ];
}
unset($report);

usort(
    $issues,
    static fn(array $a, array $b): int => strcmp($a['file'], $b['file'])
        ?: $a['line_from'] <=> $b['line_from']
        ?: $a['column_from'] <=> $b['column_from']
        ?: strcmp($a['type'], $b['type'])
        ?: strcmp($a['message'], $b['message'])
        ?: strcmp($a['severity'], $b['severity']),
);

$json = json_encode($issues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false || file_put_contents($outFile, $json) === false) {
    fwrite(STDERR, "normalize-issues: cannot write {$outFile}\n");
    exit(1);
}

echo count($issues), "\n";
