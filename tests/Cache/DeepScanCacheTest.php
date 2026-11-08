<?php

declare(strict_types=1);

namespace Psalm\Tests\Cache;

use Override;
use Psalm\Config;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\IncludeCollector;
use Psalm\Internal\Provider\ClassLikeStorageCacheProvider;
use Psalm\Internal\Provider\FakeFileProvider;
use Psalm\Internal\Provider\FileReferenceCacheProvider;
use Psalm\Internal\Provider\FileStorageCacheProvider;
use Psalm\Internal\Provider\ParserCacheProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\RuntimeCaches;
use Psalm\IssueBuffer;
use Psalm\Progress\Phase;
use Psalm\Progress\Progress;
use Psalm\Tests\Internal\Provider\ProjectCacheProvider;
use Psalm\Tests\TestCase;
use Psalm\Tests\TestConfig;

use function gc_collect_cycles;
use function rtrim;
use function sys_get_temp_dir;
use function uniqid;

use const DIRECTORY_SEPARATOR;

/**
 * The storages of a deep scan of a file are cached apart from those of a shallow one, and a scan only takes those of
 * its own depth from the cache: a file that is scanned shallowly first and deeply later (a class-like a project file
 * uses, and one that a deep-scanned class-like extends) is then scanned on the first run only.
 *
 * Each run opens the caches on disk again, as a new process does: a cache in memory would hand the next run the very
 * storage the previous run scanned deeply.
 */
final class DeepScanCacheTest extends TestCase
{
    private const CODE = '<?php final class Deep { public function run(): int { return 1; } }';

    /** One directory for the class, cache directories under it for each test */
    private static string $cache_root;

    private string $file = '';

    private FakeFileProvider $files;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // hack to isolate Psalm from PHPUnit cli arguments
        global $argv;
        $argv = [];

        self::$cache_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('psalm-deep-scan-cache-test-', true);
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        // the caches of the last run hold a lock on their directories until they are freed
        RuntimeCaches::clearAll();
        new ProjectAnalyzer(new TestConfig(), new Providers(new FakeFileProvider()));
        gc_collect_cycles();

        Config::removeCacheDirectory(self::$cache_root);

        parent::tearDownAfterClass();
    }

    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        RuntimeCaches::clearAll();

        $this->file = self::getBaseDir() . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Deep.php';
        $this->files = new FakeFileProvider();
    }

    /**
     * @psalm-external-mutation-free
     */
    #[Override]
    public function tearDown(): void
    {
        RuntimeCaches::clearAll();

        parent::tearDown();
    }

    public function testDeepScanOfAFileCachedByAShallowScanIsCached(): void
    {
        $this->files->registerFile($this->file, self::CODE);
        $cache = self::createCacheDirectory();

        self::assertSame(
            ['Scanning ' . $this->file, 'Deep scanning ' . $this->file],
            $this->scan([false, true], $cache, $cache),
        );
        self::assertSame([], $this->scan([false, true], $cache, $cache));
        self::assertSame([], $this->scan([false, true], $cache, $cache));

        $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        self::assertTrue($codebase->file_storage_provider->get($this->file)->deep_scan);
        self::assertSame(
            $this->file,
            $codebase->classlike_storage_provider->get('deep')->stmt_location?->file_path,
        );
        self::assertArrayHasKey('run', $codebase->classlike_storage_provider->get('deep')->methods);
    }

    public function testShallowScanDoesNotTakeTheStoragesOfADeepOne(): void
    {
        $this->files->registerFile($this->file, self::CODE);
        $cache = self::createCacheDirectory();

        self::assertSame(['Deep scanning ' . $this->file], $this->scan([true], $cache, $cache));

        // a storage of a deep scan has what the file uses in the bodies of its functions, which a shallow scan skips
        self::assertSame(['Scanning ' . $this->file], $this->scan([false], $cache, $cache));
        self::assertFalse(
            ProjectAnalyzer::getInstance()->getCodebase()->file_storage_provider->get($this->file)->deep_scan,
        );

        self::assertSame([], $this->scan([false, true], $cache, $cache));
    }

    public function testDeepScanOfAFileWithoutTheDeepStorageOfItsClassLike(): void
    {
        $this->files->registerFile($this->file, self::CODE);
        $file_cache = self::createCacheDirectory();
        $this->scan([false, true], $file_cache, self::createCacheDirectory());

        // the cache has both storages of the file, but only the shallow one of its class-like
        $classlike_cache = self::createCacheDirectory();
        $this->scan([false], self::createCacheDirectory(), $classlike_cache);

        self::assertSame(['Deep scanning ' . $this->file], $this->scan([false, true], $file_cache, $classlike_cache));

        $codebase = ProjectAnalyzer::getInstance()->getCodebase();
        self::assertTrue($codebase->file_storage_provider->get($this->file)->deep_scan);
        self::assertSame(['deep' => 'Deep'], $codebase->file_storage_provider->get($this->file)->classlikes_in_file);
        self::assertArrayHasKey('run', $codebase->classlike_storage_provider->get('deep')->methods);
        self::assertSame([], IssueBuffer::getIssuesData());

        // the deep scan cached the storage of the class-like
        self::assertSame([], $this->scan([false, true], $file_cache, $classlike_cache));
    }

    public function testDuplicateClassInAFileKeepsItsLocation(): void
    {
        $this->files->registerFile(
            $this->file,
            <<<'PHP'
                <?php
                if (PHP_VERSION_ID >= 80300) {
                    trait Deep
                    {
                        private int $p = 1;
                    }
                } else {
                    trait Deep
                    {
                        private ?int $p = 1;
                    }
                }
                PHP,
        );
        $cache = self::createCacheDirectory();

        // the file is never cached, as it has an issue: every run scans it again
        foreach ([[true], [false, true], [false, true], [false, true]] as $depths) {
            $this->scan($depths, $cache, $cache);

            $issues = [];

            foreach (IssueBuffer::getIssuesDataForFile($this->file) as $issue) {
                $issues[] = $issue->type . ' ' . $issue->line_from . ':' . $issue->column_from . ' ' . $issue->message;
            }

            self::assertSame(['DuplicateClass 8:11 Class Deep has already been defined in ' . $this->file], $issues);
        }
    }

    /**
     * Scans the file in one run, at each depth in turn
     *
     * @param non-empty-list<bool> $depths whether to scan deeply
     * @return list<string> the scans of the file, as the scanner reports them
     */
    private function scan(array $depths, string $file_cache_directory, string $classlike_cache_directory): array
    {
        RuntimeCaches::clearAll();

        $config = self::createConfig();

        $providers = new Providers(
            $this->files,
            new ParserCacheProvider($config, '', false),
            new FileStorageCacheProvider(self::createConfig($file_cache_directory), ''),
            new ClassLikeStorageCacheProvider(self::createConfig($classlike_cache_directory), ''),
            new FileReferenceCacheProvider($config, '', false),
            new ProjectCacheProvider(),
        );

        $file = $this->file;
        $progress = new class ($file) extends Progress {
            /** @var list<string> */
            public array $messages = [];

            public function __construct(private readonly string $file)
            {
            }

            /**
             * @psalm-capabilities read-props|write-this-props|write-refs
             */
            #[Override]
            public function debug(string $message): void
            {
                $message = rtrim($message);

                if ($message === 'Scanning ' . $this->file || $message === 'Deep scanning ' . $this->file) {
                    $this->messages[] = $message;
                }
            }

            /**
             * @psalm-capabilities read-props
             */
            #[Override]
            public function startPhase(Phase $phase, int $threads = 1): void
            {
            }

            /**
             * @psalm-capabilities read-props
             */
            #[Override]
            public function expand(int $number_of_tasks): void
            {
            }

            /**
             * @psalm-capabilities read-props
             */
            #[Override]
            public function taskDone(int $level): void
            {
            }

            /**
             * @psalm-capabilities read-props
             */
            #[Override]
            public function finish(): void
            {
            }

            /**
             * @psalm-capabilities read-props
             */
            #[Override]
            public function alterFileDone(string $file_name): void
            {
            }
        };

        $project_analyzer = new ProjectAnalyzer($config, $providers, progress: $progress);
        // the issues of the scan are only reported for the files of the project
        $project_analyzer->initProjectFiles();
        $codebase = $project_analyzer->getCodebase();

        foreach ($depths as $deep) {
            if ($deep) {
                $codebase->scanner->addFileToDeepScan($file);
            } else {
                $codebase->scanner->addFileToShallowScan($file);
            }

            $codebase->scanFiles();
        }

        return $progress->messages;
    }

    private static function createCacheDirectory(): string
    {
        return self::$cache_root . DIRECTORY_SEPARATOR . uniqid('', true);
    }

    private static function createConfig(?string $cache_directory = null): Config
    {
        $config = Config::loadFromXML(
            self::getBaseDir(),
            <<<'XML'
                <?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>
                </psalm>
                XML,
        );
        $config->setIncludeCollector(new IncludeCollector());

        if ($cache_directory !== null) {
            $config->cache_directory = $cache_directory;
        }

        return $config;
    }

    /**
     * @psalm-pure
     */
    private static function getBaseDir(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'test_base_dir';
    }
}
