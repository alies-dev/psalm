<?php
// Runs a reproducer corpus (JSONL from export_corpus.py) through one Psalm checkout with psalm.dev's
// configuration (same as ../runner/check.php), one forked child per snippet.
// Snippets with equal settings share a warm process: config, stubs and plugins are loaded once, and
// every child analyzes its snippet from a copy of that state.
//
// Usage: php run.php --psalm DIR --corpus FILE --out FILE [--jobs N] [--timeout S] [--shard I/N] [--cold]
//   --cold   no warm process: every child bootstraps on its own like check.php (for validation)
// Output JSONL, one line per snippet:
//   {"id", "status": "ok"|"internal_error"|"fatal"|"timeout", "results": [...], "error": ?string, "ms"}

declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');
ini_set('memory_limit', '-1');
ini_set('zend.assertions', '-1');

const DEFAULT_PHP_VERSION = '8.5';
const CHILD_MEMORY_LIMIT = '4G';

$o = getopt('', ['psalm:', 'corpus:', 'out:', 'jobs:', 'timeout:', 'shard:', 'cold', 'only:']);
foreach (['psalm', 'corpus', 'out'] as $required) {
    if (!isset($o[$required])) {
        fwrite(STDERR, "usage: run.php --psalm DIR --corpus FILE --out FILE [--jobs N] [--timeout S] [--shard I/N] [--cold] [--only id,id]\n");
        exit(2);
    }
}
define('PSALM_ROOT', rtrim(realpath($o['psalm']) ?: $o['psalm'], '/'));
$jobs = max(1, (int) ($o['jobs'] ?? 4));
$timeout = max(1, (int) ($o['timeout'] ?? 60));
[$shard, $shards] = array_map('intval', explode('/', $o['shard'] ?? '0/1'));
$cold = isset($o['cold']);
$only = isset($o['only']) ? array_flip(explode(',', $o['only'])) : null;

// Psalm's Config treats CLI arguments as paths to check; hide ours.
$argv = $_SERVER['argv'] = [$argv[0]];
$argc = $_SERVER['argc'] = 1;
$entries = [];
foreach (file($o['corpus'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $e = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if ($only !== null ? isset($only[$e['id']]) : crc32($e['id']) % $shards === $shard) {
        $entries[] = $e;
    }
}

// Same settings, PHP version and findUnusedCode prefix: same warm state.
$groups = [];
foreach ($entries as $e) {
    $key = json_encode([$e['settings'], $e['php'] ?? null, isUnusedCodeSnippet($e['code'])]);
    $groups[$key][] = $e;
}
ksort($groups);

$out = fopen($o['out'], 'w');
$tmp = sys_get_temp_dir() . '/issuebot-' . getmypid();
@mkdir($tmp);

require PSALM_ROOT . '/vendor/autoload.php';
if (!defined('PSALM_VERSION')) {
    define('PSALM_VERSION', \Psalm\Internal\VersionUtils::getPsalmVersion());
}
if (!defined('PHP_PARSER_VERSION')) {
    define('PHP_PARSER_VERSION', \Psalm\Internal\VersionUtils::getPhpParserVersion());
}

$t0 = hrtime(true);
foreach ($groups as $group) {
    $settings = $group[0]['settings'] ?: [];
    $php = $group[0]['php'] ?? DEFAULT_PHP_VERSION;
    $unused = isUnusedCodeSnippet($group[0]['code']);

    if ($cold) {
        runPool($group, $jobs, $timeout, $tmp, $out, static function (array $e) use ($settings, $php, $unused): array {
            return analyze(warm($settings, $php, $unused), $e['code'], $settings, $unused);
        });
        continue;
    }

    // A group master per group, so that Psalm's static state never leaks from one group into the next.
    $pid = pcntl_fork();
    if ($pid === 0) {
        $state = $warm_error = null;
        try {
            $state = warm($settings, $php, $unused);
        } catch (\Throwable $t) {
            $warm_error = $t->getMessage();
        }
        runPool($group, $jobs, $timeout, $tmp, $out, static function (array $e) use (&$state, &$warm_error, $settings, $unused): array {
            if ($state === null) {
                return ['results' => [], 'internal_error' => 'warm-up failed: ' . $warm_error];
            }
            return analyze($state, $e['code'], $settings, $unused);
        });
        fflush($out);
        exit(0);
    }
    pcntl_waitpid($pid, $status);
}
fclose($out);
// A group master that died (exit() inside Psalm, OOM while warming) leaves its snippets without a record.
$seen = [];
foreach (file($o['out'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $seen[json_decode($line, true)['id']] = true;
}
$out = fopen($o['out'], 'a');
foreach ($entries as $e) {
    if (!isset($seen[$e['id']])) {
        fwrite($out, json_encode(['id' => $e['id'], 'status' => 'fatal', 'results' => [],
            'error' => 'group master died', 'ms' => 0]) . "\n");
    }
}
fclose($out);
@rmdir($tmp);
fprintf(STDERR, "run: %d snippets in %d groups, %.1f s, shard %d/%d%s\n",
    count($entries), count($groups), (hrtime(true) - $t0) / 1e9, $shard, $shards, $cold ? ', cold' : '');

function isUnusedCodeSnippet(string $code): bool
{
    return str_starts_with($code, '<?php // findUnusedCode');
}

/**
 * @param callable(array): array{results: list<array>, internal_error: ?string} $work
 */
function runPool(array $entries, int $jobs, int $timeout, string $tmp, $out, callable $work): void
{
    $running = []; // pid => [entry, start, result file]
    $queue = $entries;
    while ($queue || $running) {
        while ($queue && count($running) < $jobs) {
            $e = array_shift($queue);
            $file = $tmp . '/' . md5($e['id']) . '.json';
            @unlink($file);
            $pid = pcntl_fork();
            if ($pid === 0) {
                ini_set('memory_limit', CHILD_MEMORY_LIMIT);
                register_shutdown_function(static function () use ($file): void {
                    if (!file_exists($file)) {
                        $err = error_get_last();
                        file_put_contents($file, json_encode(['fatal' => $err['message'] ?? 'died without output'],
                            JSON_INVALID_UTF8_SUBSTITUTE));
                    }
                });
                ob_start();
                try {
                    $r = $work($e);
                } catch (\Throwable $t) {
                    $r = ['results' => [], 'internal_error' => $t->getMessage()];
                }
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                file_put_contents($file, json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
                exit(0);
            }
            $running[$pid] = [$e, hrtime(true), $file];
        }

        $pid = pcntl_waitpid(-1, $status, WNOHANG);
        if ($pid > 0 && isset($running[$pid])) {
            [$e, $start, $file] = $running[$pid];
            unset($running[$pid]);
            $ms = (int) ((hrtime(true) - $start) / 1e6);
            $r = json_decode((string) @file_get_contents($file), true);
            @unlink($file);
            if (!is_array($r)) {
                $sig = pcntl_wifsignaled($status) ? ' (signal ' . pcntl_wtermsig($status) . ')' : '';
                $rec = ['status' => 'fatal', 'results' => [], 'error' => 'no output' . $sig];
            } elseif (isset($r['fatal'])) {
                $rec = ['status' => 'fatal', 'results' => [], 'error' => $r['fatal']];
            } elseif ($r['internal_error'] !== null) {
                $rec = ['status' => 'internal_error', 'results' => $r['results'], 'error' => $r['internal_error']];
            } else {
                $rec = ['status' => 'ok', 'results' => $r['results'], 'error' => null];
            }
            fwrite($out, json_encode(['id' => $e['id']] + $rec + ['ms' => $ms],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES) . "\n");
            continue;
        }

        $now = hrtime(true);
        foreach ($running as $pid => [$e, $start]) {
            if (($now - $start) / 1e9 > $timeout) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                unset($running[$pid]);
                fwrite($out, json_encode(['id' => $e['id'], 'status' => 'timeout', 'results' => [],
                    'error' => "timeout after {$timeout}s", 'ms' => $timeout * 1000]) . "\n");
            }
        }
        usleep(5000);
    }
}

// ---- psalm.dev's OnlineChecker, split at the point where the snippet comes in (see ../runner/check.php)

use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Provider\FakeFileProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\IssueBuffer;

function psalmDevConfig(array $settings, bool $find_unused_code): Config
{
    $unused_variables = ($settings['unused_variables'] ?? false) || $find_unused_code;
    $unused_methods = ($settings['unused_methods'] ?? false) || $find_unused_code;

    $base_dir = __DIR__;
    $config = Config::loadFromXML($base_dir, (string) file_get_contents(__DIR__ . '/psalm.xml'));
    $config->collectPredefinedConstants();
    $config->collectPredefinedFunctions();
    $config->cache_directory = null;
    $config->allow_includes = false;
    $config->show_mixed_issues = true;
    $config->ensure_array_string_offsets_exist = true;
    $config->ensure_array_int_offsets_exist = true;
    $config->check_for_throws_docblock = $settings['check_throws'] ?? true;
    $config->remember_property_assignments_after_call = $settings['memoize_properties'] ?? true;
    $config->memoize_method_calls = $settings['memoize_method_calls'] ?? false;
    $config->disable_var_parsing = $settings['disable_var_parsing'] ?? false;
    $config->use_phpdoc_method_without_magic_or_parent = $settings['use_phpdoc_without_magic_call'] ?? false;
    $config->use_phpdoc_property_without_magic_or_parent = $settings['use_phpdoc_without_magic_call'] ?? false;
    $config->ignore_internal_nullable_issues = !($settings['strict_internal_functions'] ?? false);
    $config->ignore_internal_falsable_issues = !($settings['strict_internal_functions'] ?? false);
    $config->ensure_override_attribute = $settings['ensure_override_attribute'] ?? false;
    $config->run_taint_analysis = true;
    $config->base_dir = $base_dir . '/';

    foreach ($config->php_extensions as &$enabled) {
        $enabled = true;
    }
    unset($enabled);

    if ($settings['restrict_return_types'] ?? false) {
        $config->restrict_return_types = true;
    }

    $config->addStubFile(PSALM_ROOT . '/stubs/extensions/ds.phpstub');

    foreach ([
        'MixedArrayAccess', 'MixedArrayOffset', 'MixedAssignment', 'MixedArgument', 'MixedMethodCall',
        'MixedOperand', 'MissingParamType', 'MissingClosureParamType', 'MixedTypeCoercion',
        'MixedPropertyFetch', 'MixedPropertyAssignment', 'MixedInferredReturnType', 'MixedReturnStatement',
        'MixedArrayAssignment', 'MissingPropertyType', 'MissingReturnType', 'MissingClosureReturnType',
        'MissingThrowsDocblock', 'DeprecatedMethod', 'PossiblyUndefinedGlobalVariable',
        'PossiblyUndefinedVariable', 'PossiblyUndefinedIntArrayOffset', 'PossiblyUndefinedStringArrayOffset',
        'NonStaticSelfCall', 'UndefinedAttributeClass',
    ] as $issue) {
        $config->setCustomErrorLevel($issue, Config::REPORT_INFO);
    }

    $level = $unused_variables ? Config::REPORT_INFO : Config::REPORT_SUPPRESS;
    foreach (['UnusedParam', 'PossiblyUnusedParam', 'UnusedVariable'] as $issue) {
        $config->setCustomErrorLevel($issue, $level);
    }

    $level = $unused_methods ? Config::REPORT_INFO : Config::REPORT_SUPPRESS;
    foreach (['UnusedClass', 'UnusedMethod', 'PossiblyUnusedMethod', 'PossiblyUnusedProperty', 'UnusedProperty'] as $issue) {
        $config->setCustomErrorLevel($issue, $level);
    }

    $config->setCustomErrorLevel('MoreSpecificReturnType', Config::REPORT_INFO);
    $config->setCustomErrorLevel('LessSpecificReturnStatement', Config::REPORT_INFO);

    return $config;
}

/** Everything psalm.dev does before it sees the snippet. */
function warm(array $settings, string $php_version, bool $find_unused_code): array
{
    $config = psalmDevConfig($settings, $find_unused_code);
    $file_provider = new FakeFileProvider();
    $project_checker = new ProjectAnalyzer($config, new Providers($file_provider));
    $project_checker->initExtraFiles();
    $project_checker->initProjectFiles();
    $project_checker->setPhpVersion($php_version, 'cli');

    $codebase = $project_checker->getCodebase();
    $codebase->config->initializePlugins($project_checker);
    $codebase->collect_references = true;
    $config->visitPreloadedStubFiles($codebase);
    $codebase->store_node_types = true;
    $project_checker->consolidateAnalyzedData();

    return [$config, $file_provider, $project_checker];
}

/** @return array{results: list<array>, internal_error: ?string} */
function analyze(array $state, string $file_contents, array $settings, bool $find_unused_code): array
{
    [$config, $file_provider, $project_checker] = $state;
    $codebase = $project_checker->getCodebase();
    $file_path = __DIR__ . '/src/somefile.php';
    $file_provider->registerFile($file_path, $file_contents);
    $codebase->scanner->addFileToDeepScan($file_path);

    if (($settings['unused_variables'] ?? false) || ($settings['unused_methods'] ?? false) || $find_unused_code) {
        $codebase->reportUnusedCode();
    }
    $codebase->addFilesToAnalyze([$file_path => $file_path]);

    $parse_error = static fn(\PhpParser\Error $e): array => ['results' => [[
        'type' => 'ParseError',
        'severity' => 'error',
        'line_from' => $e->getStartLine(),
        'message' => $e->getRawMessage(),
    ]], 'internal_error' => null];

    try {
        $codebase->scanFiles();
    } catch (\PhpParser\Error $e) {
        return $parse_error($e);
    }
    $config->visitStubFiles($codebase, null);

    try {
        $file_checker = new FileAnalyzer($project_checker, $file_path, 'somefile.php');
        $context = new Context();
        foreach ($codebase->file_storage_provider->get($file_path)->classlike_aliases as $aliased_class => $new_class) {
            $codebase->classlikes->addClassAlias($new_class, $aliased_class);
        }
        if ($config->run_taint_analysis) {
            $project_checker->trackTaintedInputs();
        }
        $file_checker->analyze($context);
        if ($codebase->taint_flow_graph) {
            $codebase->taint_flow_graph->connectSinksAndSources($codebase->progress);
        }
        if (($settings['unused_methods'] ?? false) || $find_unused_code) {
            $project_checker->consolidateAnalyzedData();
        }
    } catch (\PhpParser\Error $e) {
        return $parse_error($e);
    }

    $results = [];
    foreach (IssueBuffer::getIssuesData() as $file_issues) {
        foreach ($file_issues as $issue) {
            $results[] = [
                'type' => $issue->type,
                'severity' => $issue->severity,
                'line_from' => $issue->line_from,
                'message' => $issue->message,
            ];
        }
        break;
    }
    usort($results, static fn(array $a, array $b): int
        => [$a['line_from'], $a['type'], $a['message']] <=> [$b['line_from'], $b['type'], $b['message']]);

    return ['results' => $results, 'internal_error' => null];
}
