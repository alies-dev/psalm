<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use Override;
use PhpParser;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\PhpVersion;
use Psalm\CodeLocation\ParseErrorLocation;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Internal\Diff\FileDiffer;
use Psalm\Internal\Diff\FileStatementsDiffer;
use Psalm\Internal\Fork\IgbinarySerializer;
use Psalm\Internal\PhpTraverser\CustomTraverser;
use Psalm\Internal\PhpVisitor\CloningVisitor;
use Psalm\Internal\PhpVisitor\PartialApplicationVisitor;
use Psalm\Internal\PhpVisitor\PartialParserVisitor;
use Psalm\Internal\PhpVisitor\SimpleNameResolver;
use Psalm\Issue\ParseError;
use Psalm\IssueBuffer;
use Psalm\Progress\Progress;
use Psalm\Progress\VoidProgress;
use Throwable;

use function abs;
use function array_fill_keys;
use function array_intersect_key;
use function array_key_first;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function hash;
use function ini_set;
use function max;
use function md5;
use function memory_get_usage;
use function serialize;
use function str_starts_with;
use function strlen;
use function strpos;
use function unserialize;

/**
 * @internal
 */
final class StatementsProvider
{
    /**
     * @var array<string, array<string, bool>>
     */
    private array $unchanged_members = [];

    /**
     * @var array<string, array<string, bool>>
     */
    private array $unchanged_signature_members = [];

    /**
     * @var array<string, array<string, bool>>
     */
    private array $changed_members = [];

    /**
     * @var array<string, bool>
     */
    private array $errors = [];

    /**
     * @var array<string, array<int, array{int, int, int, int}>>
     */
    private array $diff_map = [];

    /**
     * @var array<string, array<int, array{int, int}>>
     */
    private array $deletion_ranges = [];

    /**
     * The statements of vendor files, which the parser cache doesn't keep, by path: the PHP version and the hash of
     * the contents they were parsed from, and from the second request on the serialized statements, method bodies
     * serialized apart (see LAZY_METHOD_BODY)
     *
     * @var array<string, array{array{int, string}, ?string}>
     */
    private array $vendor_statements = [];

    /**
     * The attribute holding the serialized statements of a method body left out of the statements of a vendor file,
     * until hydrateMethodBody() puts them back: ProjectAnalyzer::getMethodMutations() analyses one method of a file
     * thousands of times, and unserializing the bodies of all the others took most of the time
     */
    public const LAZY_METHOD_BODY = 'psalmLazyMethodBody';

    /**
     * Statements nesting deeper are not kept, as (un)serializing them could exhaust a small stack; vendor code
     * hardly ever nests more than 32 levels
     */
    private const MAX_KEPT_NESTING = 128;

    /** Bytes of statements kept for project files requested more than once, see keepStatements() */
    public int $kept_statements_budget = 32 * 1_024 * 1_024;

    private const MAX_REQUESTED_FILES = 65_536;

    /** The project file the scanner is getting the statements of, for this process to analyse it */
    private ?string $hand_off_file = null;

    /**
     * Statements of project files scanned for analysis in this process, until the next request (the analysis)
     * takes them: the contents they were parsed from, the statements and the parse errors
     *
     * @var array<string, array{string, list<Stmt>, list<PhpParser\Error>}>
     */
    private array $handed_off = [];

    /**
     * Statements of project files requested more than once, least recently used first: the contents they were
     * parsed from, the statements, the parse errors and what keeping them costs
     *
     * @var array<string, array{string, list<Stmt>, list<PhpParser\Error>, int}>
     */
    private array $kept = [];

    private int $kept_bytes = 0;

    /**
     * Project files requested once already: only those requested again are kept, so that files needed once don't
     * push out the ones needed again and again
     *
     * @var array<string, true>
     */
    private array $requested = [];

    private static ?Parser $parser = null;

    /** Whether the statements parsed are kept in memory without a disk cache */
    private bool $keep_new_statements_without_disk = true;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly FileProvider $file_provider,
        public ?ParserCacheProvider $parser_cache_provider = null,
    ) {
    }

    /**
     * @param bool $lazy_method_bodies whether the bodies of the methods may be left out, for the caller to put back
     *                                 with hydrateMethodBody() the ones it analyses
     * @return list<Stmt>
     */
    public function getStatementsForFile(
        string $file_path,
        int $analysis_php_version_id,
        bool $do_diff,
        ?Progress $progress = null,
        bool $lazy_method_bodies = false,
    ): array {
        unset($this->errors[$file_path]);

        if ($progress === null) {
            $progress = new VoidProgress();
        }

        $file_contents = $this->file_provider->getContents($file_path);

        $config = Config::getInstance();

        if (!$this->parser_cache_provider
            || (!$config->isInProjectDirs($file_path) && strpos($file_path, 'vendor'))
        ) {
            // ProjectAnalyzer::getMethodMutations() asks for the same vendor parent classes over and over. Without a
            // parser cache provider every project file comes this way too, and keeping all of them is what made the
            // former StatementsVolatileCache use 8GB+ (#9899).
            $parsed_from = $this->parser_cache_provider
                ? [$analysis_php_version_id, hash('xxh128', $file_contents)]
                : null;
            [$kept_parsed_from, $kept_statements] = $this->vendor_statements[$file_path] ?? [null, null];

            if ($kept_statements !== null && $kept_parsed_from === $parsed_from) {
                $stmts = self::unserializeStatements($kept_statements);

                if (!$lazy_method_bodies) {
                    foreach (self::getKeptMethods($stmts) as $method) {
                        self::hydrateMethodBody($method);
                    }
                }

                return $stmts;
            }

            $progress->debug('Parsing ' . $file_path . " because we cannot use cache\n");

            $parse_errors = [];

            $stmts = self::parseStatements($file_contents, $analysis_php_version_id, $parse_errors);

            self::reportParseErrors($parse_errors, $file_contents, $file_path);

            // most vendor files are only requested by the scanner, so they are kept from their second request on
            if ($parsed_from !== null && $parse_errors === []) {
                $this->vendor_statements[$file_path] = [
                    $parsed_from,
                    $kept_parsed_from === $parsed_from && self::getNesting($stmts) <= self::MAX_KEPT_NESTING
                        ? self::serializeKeptStatements($stmts)
                        : null,
                ];
            }

            return $stmts;
        }

        if (!$config->array_cache) {
            return $this->fetchStatements(
                $this->parser_cache_provider,
                $file_path,
                $file_contents,
                $analysis_php_version_id,
                $do_diff,
                $progress,
            )[0];
        }

        $kept = $this->takeKeptStatements($file_path, $file_contents);
        if ($kept !== null) {
            return $this->useCachedStatements($file_path, $file_contents, ...$kept);
        }

        $memory_before = memory_get_usage();
        [$stmts, $parse_errors] = $this->fetchStatements(
            $this->parser_cache_provider,
            $file_path,
            $file_contents,
            $analysis_php_version_id,
            $do_diff,
            $progress,
        );
        $this->keepStatements($file_path, $file_contents, $stmts, $parse_errors, $memory_before);

        return $stmts;
    }

    /**
     * Statements of the next request for the file are kept until the request after it, which is how the analysis
     * of a file gets the statements its scan parsed.
     *
     * @psalm-external-mutation-free
     */
    public function handOff(?string $file_path): void
    {
        $this->hand_off_file = $file_path;
    }

    /**
     * For when the files this process scanned were analysed in other processes
     *
     * @psalm-external-mutation-free
     */
    public function dropHandedOff(): void
    {
        $this->handed_off = [];
    }

    /**
     * @return array{list<Stmt>, list<PhpParser\Error>}|null
     * @psalm-external-mutation-free
     */
    private function takeKeptStatements(string $file_path, string $file_contents): ?array
    {
        if (isset($this->handed_off[$file_path])) {
            [$parsed_from, $stmts, $parse_errors] = $this->handed_off[$file_path];
            unset($this->handed_off[$file_path]);
            $this->requested[$file_path] = true;

            if ($parsed_from === $file_contents) {
                return [$stmts, $parse_errors];
            }
        }

        if (isset($this->kept[$file_path])) {
            $entry = $this->kept[$file_path];
            unset($this->kept[$file_path]);

            if ($entry[0] === $file_contents) {
                $this->kept[$file_path] = $entry;

                return [$entry[1], $entry[2]];
            }

            $this->kept_bytes -= $entry[3];
        }

        return null;
    }

    /**
     * Requests served from what is kept get the same statements, which analysers may have changed attributes of;
     * that is what every request got when the parser cache kept all of them in memory.
     *
     * @param list<Stmt> $stmts
     * @param list<PhpParser\Error> $parse_errors
     */
    private function keepStatements(
        string $file_path,
        string $file_contents,
        array $stmts,
        array $parse_errors,
        int $memory_before,
    ): void {
        if ($this->hand_off_file === $file_path) {
            $this->hand_off_file = null;
            $this->handed_off[$file_path] = [$file_contents, $stmts, $parse_errors];

            return;
        }

        if (!isset($this->requested[$file_path])) {
            if (count($this->requested) >= self::MAX_REQUESTED_FILES) {
                $this->requested = [];
            }

            $this->requested[$file_path] = true;

            return;
        }

        // the statements were just read or parsed, so the memory growth is about what keeping them costs
        $bytes = max(1_024, memory_get_usage() - $memory_before);

        if ($bytes > $this->kept_statements_budget) {
            return;
        }

        while ($this->kept && $this->kept_bytes + $bytes > $this->kept_statements_budget) {
            $oldest = array_key_first($this->kept);
            $this->kept_bytes -= $this->kept[$oldest][3];
            unset($this->kept[$oldest]);
        }

        $this->kept[$file_path] = [$file_contents, $stmts, $parse_errors, $bytes];
        $this->kept_bytes += $bytes;
    }

    /**
     * @return array{list<Stmt>, list<PhpParser\Error>} the statements and the errors the parser recovered from
     */
    private function fetchStatements(
        ParserCacheProvider $parser_cache_provider,
        string $file_path,
        string $file_contents,
        int $analysis_php_version_id,
        bool $do_diff,
        Progress $progress,
    ): array {
        $cached = $parser_cache_provider->loadStatementsFromCache(
            $file_path,
            $file_contents,
        );

        if ($cached !== null) {
            $progress->debug("Loading $file_path from the parser cache\n");

            return [$this->useCachedStatements($file_path, $file_contents, ...$cached), $cached[1]];
        }

        $progress->debug("Parsing $file_path because the cache is absent or outdated\n");

        $existing_statements = null;
        $existing_file_contents = null;
        $existing_statements_copy = null;
        $file_changes = null;
        if ($do_diff) {
            $existing_file_contents = $parser_cache_provider->getHash($file_path);

            $existing = $parser_cache_provider->loadStatementsFromCache(
                $file_path,
                $existing_file_contents,
            );

            $existing_statements = $existing[0] ?? null;

            // Race condition, another thread wrote the same data we already have
            if ($existing_file_contents === $file_contents && $existing !== null) {
                return [$this->useCachedStatements($file_path, $file_contents, ...$existing), $existing[1]];
            }

            $file_changes = null;

            $existing_statements_copy = null;

            if ($existing_statements !== null
                && $existing_file_contents  !== null
                && abs(strlen($existing_file_contents) - strlen($file_contents)) < 5_000
            ) {
                $file_changes = FileDiffer::getDiff($existing_file_contents, $file_contents);

                if (count($file_changes) < 10) {
                    $traverser = new PhpParser\NodeTraverser;
                    $traverser->addVisitor(new CloningVisitor);
                    // performs a deep clone
                    /** @var list<PhpParser\Node\Stmt> */
                    $existing_statements_copy = $traverser->traverse($existing_statements);
                } else {
                    $file_changes = null;
                }
            }
        }

        $parse_errors = [];

        $stmts = self::parseStatements(
            $file_contents,
            $analysis_php_version_id,
            $parse_errors,
            $existing_file_contents,
            $existing_statements_copy,
            $file_changes,
        );

        self::reportParseErrors($parse_errors, $file_contents, $file_path);

        if ($existing_file_contents !== null && $existing_statements !== null && ($parse_errors === [] || $stmts)) {
            [$unchanged_members, $unchanged_signature_members, $changed_members, $diff_map, $deletion_ranges]
                = FileStatementsDiffer::diff(
                    $existing_statements,
                    $stmts,
                    $existing_file_contents,
                    $file_contents,
                );

            $unchanged_members = array_fill_keys($unchanged_members, true);
            $unchanged_signature_members = array_fill_keys($unchanged_signature_members, true);

            // do NOT change this to hash, it will fail on Windows for whatever reason
            $file_path_hash = md5($file_path);

            $changed_members = array_map(
                static function (string $key) use ($file_path_hash): string {
                    if (str_starts_with($key, 'use:')) {
                        return $key . ':' . $file_path_hash;
                    }

                    return $key;
                },
                $changed_members,
            );

            $changed_members = array_fill_keys($changed_members, true);

            if (isset($this->unchanged_members[$file_path])) {
                $this->unchanged_members[$file_path] = array_intersect_key(
                    $this->unchanged_members[$file_path],
                    $unchanged_members,
                );
            } else {
                $this->unchanged_members[$file_path] = $unchanged_members;
            }

            if (isset($this->unchanged_signature_members[$file_path])) {
                $this->unchanged_signature_members[$file_path] = array_intersect_key(
                    $this->unchanged_signature_members[$file_path],
                    $unchanged_signature_members,
                );
            } else {
                $this->unchanged_signature_members[$file_path] = $unchanged_signature_members;
            }

            if (isset($this->changed_members[$file_path])) {
                $this->changed_members[$file_path] = array_merge(
                    $this->changed_members[$file_path],
                    $changed_members,
                );
            } else {
                $this->changed_members[$file_path] = $changed_members;
            }

            $this->diff_map[$file_path] = $diff_map;
            $this->deletion_ranges[$file_path] = $deletion_ranges;
        } elseif ($parse_errors !== [] && !$stmts) {
            $this->errors[$file_path] = true;
        }

        // The statements a cache without disk keeps are only read back to diff them with the next contents, and not by
        // a process that never asks for them again
        $parser_cache_provider->saveStatementsToCache(
            $file_path,
            $file_contents,
            $stmts,
            $parse_errors,
            $do_diff && $this->keep_new_statements_without_disk,
        );

        return [$stmts, $parse_errors];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function getChangedMembers(): array
    {
        return $this->changed_members;
    }

    /**
     * @param array<string, array<string, bool>> $more_changed_members
     * @psalm-external-mutation-free
     */
    public function addChangedMembers(array $more_changed_members): void
    {
        $this->changed_members = [...$more_changed_members, ...$this->changed_members];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function getUnchangedSignatureMembers(): array
    {
        return $this->unchanged_signature_members;
    }

    /**
     * @param array<string, array<string, bool>> $more_unchanged_members
     * @psalm-external-mutation-free
     */
    public function addUnchangedSignatureMembers(array $more_unchanged_members): void
    {
        $this->unchanged_signature_members = [...$more_unchanged_members, ...$this->unchanged_signature_members];
    }

    /**
     * @return array<string, bool>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, bool> $errors
     * @psalm-external-mutation-free
     */
    public function addErrors(array $errors): void
    {
        $this->errors += $errors;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function setUnchangedFile(string $file_path): void
    {
        if (!isset($this->diff_map[$file_path])) {
            $this->diff_map[$file_path] = [];
        }

        if (!isset($this->deletion_ranges[$file_path])) {
            $this->deletion_ranges[$file_path] = [];
        }
    }

    /**
     * @return array<string, array<int, array{int, int, int, int}>>
     */
    public function getDiffMap(): array
    {
        return $this->diff_map;
    }

    /**
     * @return array<string, array<int, array{int, int}>>
     */
    public function getDeletionRanges(): array
    {
        return $this->deletion_ranges;
    }

    /**
     * @param array<string, array<int, array{int, int, int, int}>> $diff_map
     * @psalm-external-mutation-free
     */
    public function addDiffMap(array $diff_map): void
    {
        $this->diff_map = [...$diff_map, ...$this->diff_map];
    }

    /**
     * @param array<string, array<int, array{int, int}>> $deletion_ranges
     * @psalm-external-mutation-free
     */
    public function addDeletionRanges(array $deletion_ranges): void
    {
        $this->deletion_ranges = [...$deletion_ranges, ...$this->deletion_ranges];
    }

    /**
     * Without a disk cache, stops keeping the statements parsed from now on, for a process that never asks for them
     * again
     *
     * @psalm-external-mutation-free
     */
    public function dropNewStatementsWithoutDisk(): void
    {
        $this->keep_new_statements_without_disk = false;
    }

    /**
     * @psalm-external-mutation-free
     */
    public function resetDiffs(): void
    {
        $this->changed_members = [];
        $this->unchanged_members = [];
        $this->unchanged_signature_members = [];
        $this->diff_map = [];
        $this->deletion_ranges = [];
    }

    /**
     * @param  list<PhpParser\Error>|null $parse_errors the errors the parser recovered from,
     *     and the compile errors found while resolving names (e.g. a duplicate import)
     * @param-out list<PhpParser\Error> $parse_errors
     * @param  list<Stmt> $existing_statements
     * @param  array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string}> $file_changes
     * @return list<Stmt>
     */
    public static function parseStatements(
        string  $file_contents,
        int     $analysis_php_version_id,
        ?array  &$parse_errors = null,
        ?string $existing_file_contents = null,
        ?array  $existing_statements = null,
        ?array  $file_changes = null,
    ): array {
        if (!self::$parser) {
            $major_version = Codebase::transformPhpVersionId($analysis_php_version_id, 10_000);
            $minor_version = Codebase::transformPhpVersionId($analysis_php_version_id % 10_000, 100);
            $php_version = PhpVersion::fromComponents($major_version, $minor_version);
            self::$parser = (new PhpParser\ParserFactory())->createForVersion($php_version);
        }

        $used_cached_statements = false;

        $error_handler = new Collecting();

        if ($existing_statements && $file_changes && $existing_file_contents) {
            $clashing_traverser = new CustomTraverser;
            $offset_analyzer = new PartialParserVisitor(
                self::$parser,
                $error_handler,
                $file_changes,
                $existing_file_contents,
                $file_contents,
            );
            $clashing_traverser->addVisitor($offset_analyzer);
            $clashing_traverser->traverse($existing_statements);

            if (!$offset_analyzer->mustRescan()) {
                $used_cached_statements = true;
                $stmts = $existing_statements;
            } else {
                try {
                    /** @var list<Stmt> */
                    $stmts = self::$parser->parse($file_contents, $error_handler) ?: [];
                } catch (Throwable) {
                    $stmts = [];

                    // hope this got caught below
                }
            }
        } else {
            try {
                /** @var list<Stmt> */
                $stmts = self::$parser->parse($file_contents, $error_handler) ?: [];
            } catch (Throwable) {
                $stmts = [];

                // hope this got caught below
            }
        }

        $resolving_traverser = new PhpParser\NodeTraverser;
        $name_resolver = new SimpleNameResolver(
            $error_handler,
            $used_cached_statements ? $file_changes : [],
        );
        $resolving_traverser->addVisitor($name_resolver);
        $resolving_traverser->addVisitor(new PartialApplicationVisitor());
        $resolving_traverser->traverse($stmts);

        $parse_errors = array_values($error_handler->getErrors());

        return $stmts;
    }

    /**
     * @param list<Stmt> $stmts
     * @param list<PhpParser\Error> $parse_errors
     * @return list<Stmt>
     */
    private function useCachedStatements(
        string $file_path,
        string $file_contents,
        array $stmts,
        array $parse_errors,
    ): array {
        self::reportParseErrors($parse_errors, $file_contents, $file_path);

        if ($parse_errors !== [] && !$stmts) {
            $this->errors[$file_path] = true;
        }

        $this->diff_map[$file_path] = [];
        $this->deletion_ranges[$file_path] = [];

        return $stmts;
    }

    /**
     * The parse errors of a file are reported on every run, as the issue cache never stores ParseError
     *
     * @param list<PhpParser\Error> $parse_errors
     */
    private static function reportParseErrors(array $parse_errors, string $file_contents, string $file_path): void
    {
        if ($parse_errors === []) {
            return;
        }

        $config = Config::getInstance();

        foreach ($parse_errors as $error) {
            if ($error->hasColumnInfo()) {
                IssueBuffer::maybeAdd(
                    new ParseError(
                        $error->getMessage(),
                        new ParseErrorLocation(
                            $error,
                            $file_contents,
                            $file_path,
                            $config->shortenFileName($file_path),
                        ),
                    ),
                );
            }
        }
    }

    /**
     * @param list<Stmt> $stmts
     */
    private static function getNesting(array $stmts): int
    {
        $visitor = new class extends NodeVisitorAbstract {
            public int $nesting = 0;

            private int $depth = 0;

            /**
             * @psalm-capabilities read-props|write-this-props|write-refs
             */
            #[Override]
            public function enterNode(Node $node): null
            {
                $this->nesting = max($this->nesting, ++$this->depth);

                return null;
            }

            /**
             * @psalm-capabilities read-props|write-this-props|write-refs
             */
            #[Override]
            public function leaveNode(Node $node): null
            {
                $this->depth--;

                return null;
            }
        };

        (new PhpParser\NodeTraverser($visitor))->traverse($stmts);

        return $visitor->nesting;
    }

    /**
     * What serialize() makes of the statements is safe to unserialize, whatever the file they were parsed from
     *
     * @param array<Stmt> $stmts
     * @psalm-taint-escape unserialize
     */
    private static function serializeStatements(array $stmts): string
    {
        if (Config::getInstance()->use_igbinary) {
            return (new IgbinarySerializer())->serialize($stmts);
        }

        // floats have to come back exactly, whatever the configured precision
        $precision = ini_set('serialize_precision', '-1');

        try {
            return serialize($stmts);
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }
    }

    /**
     * Serializes the statements with the bodies of their kept methods serialized apart, each in its method's
     * LAZY_METHOD_BODY attribute, leaving the statements as they were
     *
     * @param list<Stmt> $stmts
     */
    private static function serializeKeptStatements(array $stmts): string
    {
        $bodies = [];

        foreach (self::getKeptMethods($stmts) as $method) {
            if ($method->stmts !== null && $method->stmts !== []) {
                $bodies[] = [$method, $method->stmts];
                $method->setAttribute(self::LAZY_METHOD_BODY, self::serializeStatements($method->stmts));
                $method->stmts = [];
            }
        }

        try {
            return self::serializeStatements($stmts);
        } finally {
            foreach ($bodies as [$method, $body]) {
                $method->stmts = $body;
                self::removeLazyMethodBody($method);
            }
        }
    }

    /**
     * The methods of the classes and enums declared at the top level or in a namespace, whose analyzers
     * FileAnalyzer::populateCheckers() makes without analysing them: the statements around the other class-likes
     * (in an if, a function, ...) are analysed with their method bodies
     *
     * @param list<Stmt> $stmts
     * @return list<ClassMethod>
     * @psalm-capabilities read-props
     */
    private static function getKeptMethods(array $stmts): array
    {
        $methods = [];

        foreach ($stmts as $stmt) {
            foreach ($stmt instanceof Namespace_ ? $stmt->stmts : [$stmt] as $class) {
                if ($class instanceof Class_ || $class instanceof Enum_) {
                    foreach ($class->stmts as $class_stmt) {
                        if ($class_stmt instanceof ClassMethod) {
                            $methods[] = $class_stmt;
                        }
                    }
                }
            }
        }

        return $methods;
    }

    /**
     * Puts back the body of a method of statements requested with lazy method bodies, unserialized afresh like the
     * rest of a request's statements
     */
    public static function hydrateMethodBody(ClassMethod $method): void
    {
        /** @var string|null */
        $body = $method->getAttribute(self::LAZY_METHOD_BODY);

        if ($body !== null) {
            $method->stmts = self::unserializeStatements($body);
            self::removeLazyMethodBody($method);
        }
    }

    private static function removeLazyMethodBody(ClassMethod $method): void
    {
        $attributes = $method->getAttributes();
        unset($attributes[self::LAZY_METHOD_BODY]);
        $method->setAttributes($attributes);
    }

    /**
     * @return list<Stmt>
     */
    private static function unserializeStatements(string $stmts): array
    {
        /** @var list<Stmt> */
        return Config::getInstance()->use_igbinary
            ? (new IgbinarySerializer())->unserialize($stmts)
            // MAX_KEPT_NESTING bounds the depth, whatever unserialize_max_depth says
            : unserialize($stmts, ['max_depth' => 0]);
    }

    /**
     * @psalm-external-mutation-free
     */
    public static function clearParser(): void
    {
        self::$parser = null;
    }
}
