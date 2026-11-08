<?php

declare(strict_types=1);

namespace Psalm\Internal\Provider;

use Psalm\Config;
use Psalm\Internal\Cache;
use Psalm\Storage\ClassLikeStorage;
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
final class ClassLikeStorageCacheProvider
{
    /** @var Cache<ClassLikeStorage> */
    private readonly Cache $cache;

    public function __construct(Config $config, string $composerLock, bool $persistent = true)
    {
        $storage_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR;

        $dependent_files = [
            $storage_dir . 'FileStorage.php',
            $storage_dir . 'FunctionLikeStorage.php',
            $storage_dir . 'ClassLikeStorage.php',
            $storage_dir . 'MethodStorage.php',
        ];

        if ($config->eventDispatcher->hasAfterClassLikeVisitHandlers()) {
            $dependent_files = array_merge($dependent_files, $config->plugin_paths);
        }
        
        $dependencies = [$composerLock];

        foreach ($dependent_files as $dependent_file_path) {
            if (!file_exists($dependent_file_path)) {
                throw new UnexpectedValueException($dependent_file_path . ' must exist');
            }

            $dependencies []= filemtime($dependent_file_path);
        }

        $this->cache = new Cache($config, 'classlike_cache', $dependencies, $persistent);
    }

    public function consolidate(): void
    {
        $this->cache->consolidate();
    }

    /**
     * Like the file storage (see FileStorageCacheProvider::writeToCache()), each depth of scan has its own entry.
     */
    public function writeToCache(
        ClassLikeStorage $storage,
        string $file_path,
        string $file_contents,
        bool $deep,
    ): void {
        $this->cache->saveItem(
            self::getKey(strtolower($storage->name), $file_path, $deep),
            $storage,
            hash('xxh128', $file_contents),
        );
    }

    /**
     * @param lowercase-string $fq_classlike_name_lc
     */
    public function getLatestFromCache(
        string $fq_classlike_name_lc,
        ?string $file_path,
        string $file_contents,
        bool $deep,
    ): ?ClassLikeStorage {
        return $this->cache->getItem(
            self::getKey($fq_classlike_name_lc, $file_path, $deep),
            hash('xxh128', $file_contents),
        );
    }

    /**
     * @psalm-pure
     */
    private static function getKey(string $fq_classlike_name_lc, ?string $file_path, bool $deep): string
    {
        return $file_path . "\0" . $fq_classlike_name_lc . ($deep ? "\0deep" : "\0shallow");
    }
}
