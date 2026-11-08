<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal\Codebase;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psalm\Internal\Codebase\Scanner;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\IssueBuffer;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionStorage;
use Psalm\Tests\TestCase;
use Psalm\Type;

use function array_keys;
use function array_map;
use function getcwd;
use function strtolower;

use const DIRECTORY_SEPARATOR;

/**
 * What the scanner's processes scanned is merged as a single process scanning the files in order would have
 * found it, whichever process scanned a file and whenever it finished.
 *
 * @psalm-import-type ThreadData from Scanner
 * @psalm-import-type GlobalRegistrations from Scanner
 * @psalm-type Process = array{
 *     file_storage: array<lowercase-string, FileStorage>,
 *     classlike_storage: array<string, ClassLikeStorage>,
 *     scanner_data: ?ThreadData,
 *     global_registrations: array<string, GlobalRegistrations>,
 * }
 */
final class ScannerPoolMergeTest extends TestCase
{
    private const SOURCES = [
        'a' => '<?php class AFirst {} class ASecond {}',
        'b' => '<?php class B {} function pool_f(): void {} const POOL_C = "b"; class_alias(B::class, "PoolAlias");',
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
     * @param list<string> $arrival the processes, in the order they finished in (w1 scanned c, w2 b)
     */
    #[DataProvider('providerArrival')]
    public function testGlobalsAreRegisteredInTheOrderOfTheFiles(array $arrival): void
    {
        $codebase = $this->project_analyzer->getCodebase();
        $b = $this->dir . 'b.php';
        $c = $this->dir . 'c.php';
        [$f_b, $f_c, $g_b, $g_c] = [new FunctionStorage(), new FunctionStorage(), new FunctionStorage(), new FunctionStorage()];

        $processes = [
            'w1' => [$c => [
                'functions' => [['pool_f', $f_c, true], ['pool_g', $g_c, false]],
                'constants' => [['POOL_C', Type::getString('c')]],
                'class_aliases' => [['CFirst', 'PoolAlias']],
            ]],
            'w2' => [$b => [
                'functions' => [['pool_f', $f_b, true], ['pool_g', $g_b, false]],
                'constants' => [['POOL_C', Type::getString('b')]],
                'class_aliases' => [['B', 'PoolAlias']],
            ]],
        ];

        $this->merge(array_map(
            static fn(string $process): array => [
                'file_storage' => [],
                'classlike_storage' => [],
                'scanner_data' => null,
                'global_registrations' => $processes[$process],
            ],
            $arrival,
        ));

        $functions = $codebase->functions->getAllStubbedFunctions();
        // a declaration in a later file replaces a function, unless registered only if no function of its name is
        self::assertSame($f_c, $functions['pool_f']);
        self::assertSame($g_b, $functions['pool_g']);
        self::assertSame("'c'", $codebase->getStubbedConstantType('POOL_C')?->getId());
        self::assertSame('CFirst', $codebase->classlikes->getUnAliasedName('PoolAlias'));
    }

    public function testAScanningProcessRecordsWhatEachFileRegistersGlobally(): void
    {
        $codebase = $this->project_analyzer->getCodebase();
        $codebase->all_functions_global = true;
        $codebase->all_constants_global = true;
        $codebase->scanner->isForked();
        $b = $this->dir . 'b.php';

        $codebase->scanner->addFileToShallowScan($b);
        $codebase->scanner->scanFiles($codebase->classlikes);

        $registrations = $codebase->scanner->getGlobalRegistrations();
        self::assertSame([$b], array_keys($registrations));
        self::assertSame(
            [['pool_f', FileStorageProvider::getAll()[strtolower($b)]->functions['pool_f'], true]],
            $registrations[$b]['functions'] ?? null,
        );
        self::assertSame('POOL_C', $registrations[$b]['constants'][0][0] ?? null);
        self::assertSame("'b'", ($registrations[$b]['constants'][0][1] ?? null)?->getId());
        self::assertSame([['B', 'PoolAlias']], $registrations[$b]['class_aliases'] ?? null);
    }

    /**
     * @param list<Process> $processes
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
            if ($process['scanner_data'] !== null) {
                $scanner->addThreadData($process['scanner_data']);
            }

            $pool_results[] = [
                'file_storage' => $process['file_storage'],
                'classlike_storage' => $process['classlike_storage'],
                'global_registrations' => $process['global_registrations'],
            ];
        }

        $scanner->addPoolResults($files_to_scan, $pool_results);
    }

    /**
     * Scans files as a process that has seen nothing else would, then forgets them.
     *
     * @param list<string> $keys
     * @return Process
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
            'global_registrations' => [],
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
