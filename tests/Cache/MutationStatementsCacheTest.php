<?php

declare(strict_types=1);

namespace Psalm\Tests\Cache;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\IncludeCollector;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageCacheProvider;
use Psalm\Internal\Provider\FakeFileProvider;
use Psalm\Internal\Provider\FileReferenceCacheProvider;
use Psalm\Internal\Provider\FileStorageCacheProvider;
use Psalm\Internal\Provider\ParserCacheProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\RuntimeCaches;
use Psalm\IssueBuffer;
use Psalm\Tests\Internal\Provider\ProjectCacheProvider;
use Psalm\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_exists;
use function microtime;
use function rmdir;
use function sort;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;

/**
 * Inlining $this->method() and parent::method() calls of a constructor (ProjectAnalyzer::getMethodMutations())
 * requests the statements of the declaring file again and again.
 */
final class MutationStatementsCacheTest extends TestCase
{
    private const FILE = 'somefile.php';

    private const PROJECT_FILES = [
        'Base.php' => '<?php
            class Base {
                public string $name;
                public int $size;

                public function __construct() {
                    $this->setUp();
                }

                protected function setUp(): void {
                    $this->name = "base";
                    $this->size = 1;
                }
            }',
        'Child1.php' => '<?php
            final class Child1 extends Base {
                public string $label;

                public function __construct() {
                    parent::__construct();
                    $this->fill();
                }

                private function fill(): void {
                    $this->label = $this->name . $this->size;
                }
            }',
        'Child2.php' => '<?php
            final class Child2 extends Base {
                public string $other;

                public function __construct() {
                    parent::__construct();
                }
            }',
        'Child3.php' => '<?php
            final class Child3 extends Base {
                public function __construct() {
                }
            }',
        'Child4.php' => '<?php
            final class Child4 extends Base {
                public function run(): string {
                    return $this->size;
                }
            }',
    ];

    /** One directory for the class, a cache directory under it for each test */
    private static string $cache_root;

    private string $cache_directory;

    private StatementsReadCounter $read_counter;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // ProjectAnalyzer::check() would analyse the files PHPUnit was given too
        global $argv;
        $argv = [];

        self::$cache_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('psalm-mutation-stmts-', true);
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$cache_root)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$cache_root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            // Config::removeCacheDirectory() would wait forever for the lock the Cache of ProjectAnalyzer::$instance
            // holds; Windows refuses to delete the lock file, hence best effort
            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }

            @rmdir(self::$cache_root);
        }

        parent::tearDownAfterClass();
    }

    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->cache_directory = self::$cache_root . DIRECTORY_SEPARATOR . uniqid('', true);
        $this->read_counter = new StatementsReadCounter(self::FILE);
    }

    #[DataProvider('provideArrayCache')]
    public function testInlinedMethodStatementsAreReadOnceTheyAreRequestedAgain(
        bool $array_cache,
        int $expected_reads,
    ): void {
        $this->useParserCache($array_cache);

        $this->addFile(
            self::FILE,
            '<?php
                class UserViewData {
                    /** @var string|null */
                    public $name;
                }

                class Controller {
                    public UserViewData $user_viewdata;

                    public ?string $title = null;

                    public function __construct() {
                        $this->user_viewdata = new UserViewData();
                    }

                    public function setUser(): void
                    {
                        $this->user_viewdata->name = "bob";
                    }
                }

                class FooController extends Controller {
                    public function barBar(): void {
                        $this->setUser();

                        if (rand(0, 1)) {
                            $this->title = "hello";
                        }
                    }
                }',
        );

        new FileAnalyzer($this->project_analyzer, self::FILE, self::FILE);
        $this->project_analyzer->getCodebase()->scanFiles();
        $this->read_counter->reads = 0;

        for ($i = 0; $i < 5; $i++) {
            $method_context = new Context();
            $method_context->collect_mutations = true;
            $this->project_analyzer->getMethodMutations(
                new MethodIdentifier('FooController', 'barbar'),
                $method_context,
                self::FILE,
                self::FILE,
            );

            $this->assertSame('UserViewData', (string) $method_context->vars_in_scope['$this->user_viewdata']);
            $this->assertSame('string', (string) $method_context->vars_in_scope['$this->user_viewdata->name']);
            $this->assertTrue($method_context->vars_possibly_in_scope['$this->title']);
        }

        $this->assertSame($expected_reads, $this->read_counter->reads);
    }

    /**
     * Each call requests the file twice: for barBar() and for setUser() inlined into it. The scan requested it once
     * already, so with arrayCache the first of those requests is the only one that reads it.
     *
     * @return iterable<string, array{bool, int}>
     * @psalm-pure
     */
    public static function provideArrayCache(): iterable
    {
        yield 'arrayCache off' => [false, 10];
        yield 'arrayCache on' => [true, 1];
    }

    /**
     * @param list<int> $expected_reads of each request after the scan
     */
    #[DataProvider('provideReadsAfterTheScan')]
    public function testAnalysisGetsTheStatementsTheScanParsed(bool $array_cache, array $expected_reads): void
    {
        $this->useParserCache($array_cache);
        $this->addFile(self::FILE, '<?php class A {}');
        $codebase = $this->project_analyzer->getCodebase();
        $codebase->addFilesToAnalyze([self::FILE => self::FILE]);
        $codebase->scanFiles();

        $reads = [];
        for ($i = 0; $i < 3; $i++) {
            $this->read_counter->reads = 0;
            $codebase->getStatementsForFile(self::FILE);
            $reads[] = $this->read_counter->reads;
        }

        $this->assertSame($expected_reads, $reads);
    }

    /**
     * @return iterable<string, array{bool, list<int>}>
     * @psalm-pure
     */
    public static function provideReadsAfterTheScan(): iterable
    {
        yield 'arrayCache off' => [false, [1, 1, 1]];
        // the analysis takes what the scan parsed, the request after it is the second one to read the file
        yield 'arrayCache on' => [true, [0, 1, 0]];
    }

    /**
     * A request served from kept statements gets the very statements an earlier analysis went through, any other
     * request gets them as the cache or the parser gives them: the issues are the same either way.
     */
    #[DataProvider('provideCachePersistence')]
    public function testKeptStatementsGiveTheIssuesFreshStatementsGive(bool $persistent): void
    {
        $unlimited = $this->checkProject($persistent, true, PHP_INT_MAX);

        // Child1 initialises everything through the methods its constructor calls, which the analysis inlines
        $this->assertSame(
            [
                'src/Child2.php:3:31 PropertyNotSetInConstructor: Property Child2::$other is not defined in constructor'
                    . ' of Child2 or in any methods called in the constructor',
                'src/Child3.php:2:25 PropertyNotSetInConstructor: Property Child3::$name is not defined in constructor'
                    . ' of Child3 or in any methods called in the constructor',
                'src/Child3.php:2:25 PropertyNotSetInConstructor: Property Child3::$size is not defined in constructor'
                    . ' of Child3 or in any methods called in the constructor',
                "src/Child4.php:3:40 InvalidReturnType: The declared return type 'string' for Child4::run is"
                    . " incorrect, got 'int'",
                "src/Child4.php:4:28 InvalidReturnStatement: The inferred type 'int' does not match the declared"
                    . " return type 'string' for Child4::run",
            ],
            $unlimited,
        );
        $this->assertSame($unlimited, $this->checkProject($persistent, true, 0), 'nothing kept after the analysis');
        $this->assertSame($unlimited, $this->checkProject($persistent, false, PHP_INT_MAX), 'arrayCache off');
    }

    /**
     * @return iterable<string, array{bool}>
     * @psalm-pure
     */
    public static function provideCachePersistence(): iterable
    {
        yield 'no cache' => [false];
        yield 'cold cache' => [true];
    }

    /**
     * @return list<string>
     */
    private function checkProject(bool $persistent, bool $array_cache, int $kept_statements_budget): array
    {
        $config = Config::loadFromXML(
            __DIR__ . DIRECTORY_SEPARATOR . 'test_base_dir',
            '<?xml version="1.0"?>
            <psalm>
                <projectFiles>
                    <directory name="src" />
                </projectFiles>
            </psalm>',
        );
        $config->setIncludeCollector(new IncludeCollector());
        $config->cache_directory = $this->cache_directory . DIRECTORY_SEPARATOR . uniqid('', true);
        $config->array_cache = $array_cache;

        $file_provider = new FakeFileProvider();
        foreach (self::PROJECT_FILES as $file_name => $contents) {
            $file_provider->registerFile(
                $config->base_dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $file_name,
                $contents,
            );
        }

        $providers = new Providers(
            $file_provider,
            new ParserCacheProvider($config, '', $persistent),
            new FileStorageCacheProvider($config, '', $persistent),
            new ClassLikeStorageCacheProvider($config, '', $persistent),
            new FileReferenceCacheProvider($config, '', $persistent),
            new ProjectCacheProvider(),
        );
        $providers->statements_provider->kept_statements_budget = $kept_statements_budget;

        RuntimeCaches::clearAll();

        $start_time = microtime(true);
        $project_analyzer = new ProjectAnalyzer($config, $providers);
        $project_analyzer->check($config->base_dir, true);
        $project_analyzer->finish($start_time, PSALM_VERSION);

        $issues = [];
        foreach (IssueBuffer::getIssuesData() as $issues_of_file) {
            foreach ($issues_of_file as $issue) {
                $issues[] = "$issue->file_name:$issue->line_from:$issue->column_from $issue->type: $issue->message";
            }
        }

        sort($issues);

        return $issues;
    }

    private function useParserCache(bool $array_cache): void
    {
        $this->testConfig->cache_directory = $this->cache_directory;
        $this->testConfig->array_cache = $array_cache;
        RuntimeCaches::clearAll();

        $this->project_analyzer = new ProjectAnalyzer(
            $this->testConfig,
            new Providers($this->file_provider, new ParserCacheProvider($this->testConfig, '')),
            progress: $this->read_counter,
        );
        $this->project_analyzer->setPhpVersion('7.4', 'tests');
    }
}
