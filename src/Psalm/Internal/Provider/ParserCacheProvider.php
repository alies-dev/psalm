<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use PhpParser;
use Psalm\Config;
use Psalm\Internal\Cache;

use function array_map;
use function filemtime;

use const DIRECTORY_SEPARATOR;

/** @internal */
final class ParserCacheProvider
{
    private const PARSER_CACHE_DIRECTORY = 'php-parser';

    /**
     * The statements of a file, and the raw message and attributes of each error the parser recovered from, which
     * are stored rather than the exceptions, as those carry their stack trace
     *
     * @var Cache<array{list<PhpParser\Node\Stmt>, list<array{string, array<string, mixed>}>}>
     */
    private readonly Cache $stmtCache;

    /**
     * Contents of files whose statements a cache without disk did not keep, which they were parsed from
     *
     * @var array<string, string>
     */
    private array $parsed_contents = [];

    public function __construct(Config $config, string $composerLock, private readonly bool $persistent = true)
    {
        $deps = [
            $composerLock,
            PHP_PARSER_VERSION,
            (string) filemtime(__DIR__.DIRECTORY_SEPARATOR.'StatementsProvider.php'),
        ];

        $this->stmtCache = new Cache($config, self::PARSER_CACHE_DIRECTORY, $deps, $persistent);
    }

    public function consolidate(): void
    {
        $this->stmtCache->consolidate();
    }

    /**
     * @return array{list<PhpParser\Node\Stmt>, list<PhpParser\Error>}|null
     */
    public function loadStatementsFromCache(
        string $file_path,
        ?string $file_content_hash,
    ): ?array {
        $item = $this->stmtCache->getItem($file_path, $file_content_hash);

        if ($item === null) {
            return null;
        }

        return [
            $item[0],
            array_map(
                static fn(array $error): PhpParser\Error => new PhpParser\Error($error[0], $error[1]),
                $item[1],
            ),
        ];
    }

    public function getHash(string $file_path): ?string
    {
        return $this->parsed_contents[$file_path] ?? $this->stmtCache->getHash($file_path);
    }

    /**
     * Whether the cached statements of a file were parsed from other contents. A file that was never cached has
     * nothing to be stale. One with cached statements but no usable stored hash can no longer be trusted.
     */
    public function hasChanged(string $file_path, string $file_contents): bool
    {
        $hash = $this->getHash($file_path);
        if ($hash !== null) {
            return $hash !== $file_contents;
        }

        return $this->stmtCache->hasItem($file_path);
    }

    /**
     * @param  list<PhpParser\Node\Stmt>        $stmts
     * @param  list<PhpParser\Error>            $parse_errors
     * @param  bool                             $keep_statements whether a cache without disk keeps the statements,
     *     rather than only the contents they were parsed from
     */
    public function saveStatementsToCache(
        string $file_path,
        string $file_content_hash,
        array $stmts,
        array $parse_errors,
        bool $keep_statements = true,
    ): void {
        if (!$this->persistent && !$keep_statements) {
            $this->stmtCache->forgetItem($file_path);
            $this->parsed_contents[$file_path] = $file_content_hash;

            return;
        }

        unset($this->parsed_contents[$file_path]);
        $this->stmtCache->saveItem(
            $file_path,
            [
                $stmts,
                array_map(
                    static fn(PhpParser\Error $error): array => [$error->getRawMessage(), $error->getAttributes()],
                    $parse_errors,
                ),
            ],
            $file_content_hash,
        );
    }
}
