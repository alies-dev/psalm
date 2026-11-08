<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal\Codebase;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\IssueBuffer;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Tests\TestCase;

use function array_keys;
use function array_map;
use function getcwd;

use const DIRECTORY_SEPARATOR;

/**
 * What the scanner's processes scanned is merged as a single process scanning the files in order would have
 * found it, whichever process scanned a file and whenever it finished.
 */
final class ScannerPoolMergeTest extends TestCase
{
    private const SOURCES = [
        'a' => '<?php class AFirst {} class ASecond {}',
        'b' => '<?php class B {}',
        'c' => '<?php class CFirst {} class CSecond {}',
        'd' => '<?php class D {}',
    ];

    private string $dir;

    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->dir = (string) getcwd() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'pool_merge' . DIRECTORY_SEPARATOR;

        foreach (self::SOURCES as $key => $contents) {
            $this->file_provider->registerFile($this->dir . $key . '.php', $contents);
        }

        $this->forget();
    }

    /**
     * @param list<string> $arrival the processes, in the order they finished in (w1 scanned a and c, w2 b and d)
     */
    #[DataProvider('providerArrival')]
    public function testStoragesAreAddedInTheOrderOfTheFiles(array $arrival): void
    {
        $codebase = $this->project_analyzer->getCodebase();
        $single_process = $this->scanAsProcess(['a', 'b', 'c', 'd']);

        $processes = [
            'w1' => $this->scanAsProcess(['a', 'c']),
            'w2' => $this->scanAsProcess(['b', 'd']),
        ];

        $this->merge(array_map(static fn(string $process): array => $processes[$process], $arrival));

        $file_paths = array_keys($single_process['file_storage']);
        $classlike_names = array_keys($single_process['classlike_storage']);

        self::assertSame($file_paths, array_keys(FileStorageProvider::getAll()));
        self::assertSame($file_paths, array_keys($codebase->file_storage_provider->getNew()));
        self::assertSame($classlike_names, array_keys(ClassLikeStorageProvider::getAll()));
        self::assertSame($classlike_names, array_keys($codebase->classlike_storage_provider->getNew()));
    }

    /**
     * @return iterable<string, array{list<string>}>
     * @psalm-pure
     */
    public static function providerArrival(): iterable
    {
        yield 'process with the first file finishes first' => [['w1', 'w2']];
        yield 'process with the first file finishes last' => [['w2', 'w1']];
    }

    /**
     * @param list<array{
     *     file_storage: array<lowercase-string, FileStorage>,
     *     classlike_storage: array<string, ClassLikeStorage>,
     *     scanner_data: mixed,
     * }> $processes
     */
    private function merge(array $processes): void
    {
        $scanner = $this->project_analyzer->getCodebase()->scanner;
        $files_to_scan = [];

        foreach (array_keys(self::SOURCES) as $key) {
            $files_to_scan[$this->dir . $key . '.php'] = $this->dir . $key . '.php';
        }

        $pool_results = [];

        foreach ($processes as $process) {
            /** @psalm-suppress MixedArgument */
            $scanner->addThreadData($process['scanner_data']);
            $pool_results[] = [
                'file_storage' => $process['file_storage'],
                'classlike_storage' => $process['classlike_storage'],
            ];
        }

        $scanner->addPoolResults($files_to_scan, $pool_results);
    }

    /**
     * Scans files as a process that has seen nothing else would, then forgets them.
     *
     * @param list<string> $keys
     * @return array{
     *     file_storage: array<lowercase-string, FileStorage>,
     *     classlike_storage: array<string, ClassLikeStorage>,
     *     scanner_data: mixed,
     * }
     */
    private function scanAsProcess(array $keys): array
    {
        $codebase = $this->project_analyzer->getCodebase();

        foreach ($keys as $key) {
            $codebase->scanner->addFileToShallowScan($this->dir . $key . '.php');
        }

        $codebase->scanner->scanFiles($codebase->classlikes);

        $result = [
            'file_storage' => FileStorageProvider::getAll(),
            'classlike_storage' => ClassLikeStorageProvider::getAll(),
            'scanner_data' => $codebase->scanner->getThreadData(),
        ];

        $this->forget();

        return $result;
    }

    private function forget(): void
    {
        $scanner = $this->project_analyzer->getCodebase()->scanner;

        foreach (array_keys(ClassLikeStorageProvider::getAll()) as $name_lc) {
            $scanner->removeClassLike($name_lc);
        }

        foreach (array_keys(self::SOURCES) as $key) {
            $scanner->removeFile($this->dir . $key . '.php');
        }

        ClassLikeStorageProvider::deleteAll();
        FileStorageProvider::deleteAll();
        FileStorageProvider::populated();
        IssueBuffer::clear();
    }
}
