<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use Psalm\Config;
use Psalm\Internal\Cache;
use Psalm\Storage\FileStorage;
use UnexpectedValueException;

use function array_merge;
use function dirname;
use function file_exists;
use function filemtime;
use function hash;
use function strtolower;

use const DIRECTORY_SEPARATOR;

/**
 * @internal
 */
final class FileStorageCacheProvider
{
    /** @var Cache<FileStorage> */
    private readonly Cache $cache;

    private const FILE_STORAGE_CACHE_DIRECTORY = 'file_cache';

    public function __construct(Config $config, string $composerLock, bool $persistent = true)
    {
        $storage_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR;

        $dependent_files = [
            $storage_dir . 'FileStorage.php',
            $storage_dir . 'FunctionLikeStorage.php',
            $storage_dir . 'ClassLikeStorage.php',
            $storage_dir . 'MethodStorage.php',
            $storage_dir . 'FunctionLikeParameter.php',
        ];

        if ($config->eventDispatcher->hasAfterClassLikeVisitHandlers()) {
            $dependent_files = array_merge($dependent_files, $config->plugin_paths);
        }

        $dependencies = [$composerLock];
        foreach ($dependent_files as $dependent_file_path) {
            if (!file_exists($dependent_file_path)) {
                throw new UnexpectedValueException($dependent_file_path . ' must exist');
            }

            $dependencies []= (int) filemtime($dependent_file_path);
        }

        $this->cache = new Cache($config, self::FILE_STORAGE_CACHE_DIRECTORY, $dependencies, $persistent);
    }

    public function consolidate(): void
    {
        $this->cache->consolidate();
    }
    
    /**
     * A deep scan stores more than a shallow one of the same contents, so each depth has its own entry: under one
     * key the cache would skip the storage of the second scan as the one it already has.
     */
    public function writeToCache(FileStorage $storage, string $file_contents): void
    {
        $this->cache->saveItem(
            self::getKey($storage->file_path, $storage->deep_scan),
            $storage,
            hash('xxh128', $file_contents),
        );
    }

    /**
     * Makes the next writeToCache() of the file at that depth store its storage, even if the contents are the same.
     */
    public function forget(string $file_path, bool $deep): void
    {
        $this->cache->forgetItem(self::getKey($file_path, $deep));
    }

    /**
     * @param bool $deep whether the storage must come from a deep scan or from a shallow one (a deep one would make
     *     a shallow scan see what only the deep one finds)
     */
    public function getLatestFromCache(string $file_path, string $file_contents, bool $deep): ?FileStorage
    {
        return $this->cache->getItem(self::getKey($file_path, $deep), hash('xxh128', $file_contents));
    }

    /**
     * @psalm-pure
     */
    private static function getKey(string $file_path, bool $deep): string
    {
        return strtolower($file_path) . ($deep ? "\0deep" : "\0shallow");
    }
}
