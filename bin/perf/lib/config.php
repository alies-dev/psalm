<?php

declare(strict_types=1);

/**
 * @param list<string> $args
 */
function runConfig(array $args): int
{
    $options = parseOptions($args, ['corpus', 'project']);
    requireOptions($options, ['corpus', 'project']);
    $corpus = readJsonFile($options['corpus']);
    $project = $corpus['projects'][$options['project']] ?? null;
    if (!is_array($project)) {
        throw new RuntimeException("unknown project '{$options['project']}'");
    }

    echo buildConfig($project);

    return 0;
}

function xmlAttr(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * The project's psalm-perf.xml: fixed attributes plus the corpus `psalm.level`, `psalm.dirs` and `psalm.ignore`
 * (`vendor` always ignored).
 *
 * @param array<mixed> $project
 * @throws RuntimeException
 */
function buildConfig(array $project): string
{
    $psalm = $project['psalm'] ?? null;
    if (!is_array($psalm) || !is_array($psalm['dirs'] ?? null) || $psalm['dirs'] === []) {
        throw new RuntimeException('project has no psalm.dirs');
    }

    $directories = static fn(array $dirs, string $indent): string => implode('', array_map(
        static fn(string $dir): string => "{$indent}<directory name=\"" . xmlAttr($dir) . "\"/>\n",
        $dirs,
    ));

    return "<?xml version=\"1.0\"?>\n"
        . "<psalm\n"
        . '    errorLevel="' . xmlAttr((string) ($psalm['level'] ?? 1)) . "\"\n"
        . "    resolveFromConfigFile=\"true\"\n"
        . "    findUnusedCode=\"false\"\n"
        . "    findUnusedBaselineEntry=\"false\"\n"
        . "    findUnusedIssueHandlerSuppression=\"false\"\n"
        . "    xmlns=\"https://getpsalm.org/schema/config\"\n"
        . ">\n"
        . "    <projectFiles>\n"
        . $directories($psalm['dirs'], '        ')
        . "        <ignoreFiles allowMissingFiles=\"true\">\n"
        . $directories([...($psalm['ignore'] ?? []), 'vendor'], '            ')
        . "        </ignoreFiles>\n"
        . "    </projectFiles>\n"
        . "</psalm>\n";
}
