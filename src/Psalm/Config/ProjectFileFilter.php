<?php

declare(strict_types=1);

namespace Psalm\Config;

use Override;
use Psalm\Exception\ConfigException;
use SimpleXMLElement;

use function str_starts_with;
use function stripos;

/** @internal */
final class ProjectFileFilter extends FileFilter
{
    private ?ProjectFileFilter $file_filter = null;

    /**
     * allows() by case sensitivity, then file name: it is asked about the same few files for every issue and call
     *
     * @var array{array<string, bool>, array<string, bool>}
     */
    private array $allows_cache = [[], []];

    #[Override]
    public static function loadFromXMLElement(
        SimpleXMLElement $e,
        string $base_dir,
        bool $inclusive,
    ): static {
        $filter = parent::loadFromXMLElement($e, $base_dir, $inclusive);

        if (isset($e->ignoreFiles)) {
            if (!$inclusive) {
                throw new ConfigException('Cannot nest ignoreFiles inside itself');
            }

            $filter->file_filter = static::loadFromXMLElement($e->ignoreFiles, $base_dir, false);
        }

        return $filter;
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function allows(string $file_name, bool $case_sensitive = false): bool
    {
        if (isset($this->allows_cache[(int) $case_sensitive][$file_name])) {
            return $this->allows_cache[(int) $case_sensitive][$file_name];
        }

        $allows = (!$this->inclusive || !$this->file_filter || $this->file_filter->allows($file_name, $case_sensitive))
            && parent::allows($file_name, $case_sensitive);

        /** @psalm-suppress ImpurePropertyAssignment Cache */
        $this->allows_cache[(int) $case_sensitive][$file_name] = $allows;

        return $allows;
    }

    /**
     * @psalm-mutation-free
     */
    public function forbids(string $file_name, bool $case_sensitive = false): bool
    {
        if ($this->inclusive && $this->file_filter) {
            if (!$this->file_filter->allows($file_name, $case_sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-external-mutation-free
     */
    #[Override]
    public function addFile(string $file_name): void
    {
        parent::addFile($file_name);
        $this->allows_cache = [[], []];
    }

    /**
     * @psalm-external-mutation-free
     */
    #[Override]
    public function addDirectory(string $dir_name): void
    {
        parent::addDirectory($dir_name);
        $this->allows_cache = [[], []];
    }

    /**
     * @psalm-mutation-free
     */
    public function reportTypeStats(string $file_name, bool $case_sensitive = false): bool
    {
        foreach ($this->ignore_type_stats as $exclude_dir => $_) {
            if ($case_sensitive) {
                if (str_starts_with($file_name, $exclude_dir)) {
                    return false;
                }
            } else {
                if (stripos($file_name, $exclude_dir) === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @psalm-mutation-free
     */
    public function useStrictTypes(string $file_name, bool $case_sensitive = false): bool
    {
        foreach ($this->declare_strict_types as $exclude_dir => $_) {
            if ($case_sensitive) {
                if (str_starts_with($file_name, $exclude_dir)) {
                    return true;
                }
            } else {
                if (stripos($file_name, $exclude_dir) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
