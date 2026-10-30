<?php

declare(strict_types=1);

/**
 * Issue multiset diff. An issue's identity is file, line_from, line_to, column_from, column_to, type, severity and
 * message. Leftovers at the same file, line_from and type are paired up as "changed" (different message or severity).
 */

const KEY_FILE = 0;
const KEY_LINE = 1;
const KEY_TYPE = 5;
const KEY_SEVERITY = 6;
const KEY_MESSAGE = 7;

/**
 * @param array<string, mixed> $issue
 */
function issueKey(array $issue): string
{
    return implode("\0", [
        (string) ($issue['file'] ?? ''),
        (string) ($issue['line_from'] ?? 0),
        (string) ($issue['line_to'] ?? 0),
        (string) ($issue['column_from'] ?? 0),
        (string) ($issue['column_to'] ?? 0),
        (string) ($issue['type'] ?? '?'),
        (string) ($issue['severity'] ?? ''),
        (string) ($issue['message'] ?? ''),
    ]);
}

/**
 * @param list<array<string, mixed>> $issues
 * @return array<string, int>
 */
function tally(array $issues): array
{
    $counts = [];
    foreach ($issues as $issue) {
        $key = issueKey($issue);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    return $counts;
}

/**
 * One list element per occurrence of every key that is more frequent in $from than in $against.
 *
 * @param array<string, int> $from
 * @param array<string, int> $against
 * @return list<list<string>> exploded keys, sorted
 */
function surplusParts(array $from, array $against): array
{
    $parts = [];
    foreach ($from as $key => $n) {
        for ($k = $n - ($against[$key] ?? 0); $k > 0; $k--) {
            $parts[] = explode("\0", $key);
        }
    }

    sort($parts);

    return $parts;
}

/** @param list<string> $p */
function locOf(array $p): string
{
    return "{$p[KEY_FILE]}:{$p[KEY_LINE]}";
}

/**
 * @param list<array<string, mixed>> $base
 * @param list<array<string, mixed>> $head
 * @return array{
 *     added: list<array{type: string, severity: string, loc: string, message: string}>,
 *     removed: list<array{type: string, severity: string, loc: string, message: string}>,
 *     changed: list<array{
 *         type: string,
 *         loc: string,
 *         old: string,
 *         new: string,
 *         old_severity: string,
 *         new_severity: string
 *     }>
 * }
 */
function diffIssues(array $base, array $head): array
{
    $baseCounts = tally($base);
    $headCounts = tally($head);
    $removed = surplusParts($baseCounts, $headCounts);
    $added = surplusParts($headCounts, $baseCounts);

    $groupOf = static fn(array $p): string => "{$p[KEY_FILE]}\0{$p[KEY_LINE]}\0{$p[KEY_TYPE]}";
    $addedByGroup = [];
    foreach ($added as $i => $p) {
        $addedByGroup[$groupOf($p)][] = $i;
    }

    // Same message first (a pure severity transition), then whatever is left in the group, in order. Leftovers that
    // differ in neither message nor severity moved (line_to or columns changed): those stay added + removed.
    $pairs = [];
    foreach ([true, false] as $sameMessageOnly) {
        foreach ($removed as $r => $old) {
            foreach ($addedByGroup[$groupOf($old)] ?? [] as $slot => $a) {
                $sameMessage = $added[$a][KEY_MESSAGE] === $old[KEY_MESSAGE];
                $sameSeverity = $added[$a][KEY_SEVERITY] === $old[KEY_SEVERITY];
                if (($sameMessageOnly && !$sameMessage) || ($sameMessage && $sameSeverity)) {
                    continue;
                }

                $pairs[] = [$old, $added[$a]];
                unset($removed[$r], $addedByGroup[$groupOf($old)][$slot], $added[$a]);
                break;
            }
        }
    }

    $changed = array_map(
        static fn(array $pair): array => [
            'type' => $pair[0][KEY_TYPE],
            'loc' => locOf($pair[0]),
            'old' => $pair[0][KEY_MESSAGE],
            'new' => $pair[1][KEY_MESSAGE],
            'old_severity' => $pair[0][KEY_SEVERITY],
            'new_severity' => $pair[1][KEY_SEVERITY],
        ],
        $pairs,
    );
    usort($changed, static fn(array $a, array $b): int => [$a['loc'], $a['type']] <=> [$b['loc'], $b['type']]);

    $entry = static fn(array $p): array => [
        'type' => $p[KEY_TYPE],
        'severity' => $p[KEY_SEVERITY],
        'loc' => locOf($p),
        'message' => $p[KEY_MESSAGE],
    ];

    return [
        'added' => array_map($entry, array_values($added)),
        'removed' => array_map($entry, array_values($removed)),
        'changed' => $changed,
    ];
}

/**
 * Per issue type counts of a diff, to spot volatile types.
 *
 * @param array{
 *     added: list<array{type: string}>,
 *     removed: list<array{type: string}>,
 *     changed: list<array{type: string}>
 * } $diff
 * @return array<string, array{added: int, removed: int, changed: int}>
 */
function countByType(array $diff): array
{
    $types = [];
    foreach (['added', 'removed', 'changed'] as $kind) {
        foreach ($diff[$kind] as $e) {
            $types[$e['type']] ??= ['added' => 0, 'removed' => 0, 'changed' => 0];
            $types[$e['type']][$kind]++;
        }
    }

    ksort($types);

    return $types;
}
