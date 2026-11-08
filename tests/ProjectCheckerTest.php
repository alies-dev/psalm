<?php

declare(strict_types=1);

namespace Psalm\Tests;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Report;
use Psalm\Report\ReportOptions;
use Psalm\Tests\Internal\Provider\ProjectCacheProvider;
use Psalm\Tests\Progress\EchoProgress;
use Psalm\Tests\TestConfig;

use function get_class;
use function getcwd;
use function microtime;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;
use function realpath;

use const DIRECTORY_SEPARATOR;

final class ProjectCheckerTest extends TestCase
{
    protected ProjectAnalyzer $project_analyzer;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // hack to stop Psalm seeing the phpunit arguments
        global $argv;
        $argv = [];

        new TestConfig();
    }

    /**
     * @psalm-external-mutation-free
     */
    #[Override]
    public function setUp(): void
    {
        RuntimeCaches::clearAll();
        $this->file_provider = new FakeFileProvider();
    }

    private function getProjectAnalyzerWithConfig(Config $config, bool $init_files = true): ProjectAnalyzer
    {
        $config->setIncludeCollector(new IncludeCollector());
        $ret = new ProjectAnalyzer(
            $config,
            new Providers(
                $this->file_provider,
                new ParserCacheProvider($config, '', false),
                new FileStorageCacheProvider($config, '', false),
                new ClassLikeStorageCacheProvider($config, '', false),
                new FileReferenceCacheProvider($config, '', false),
                new ProjectCacheProvider(),
            ),
            new ReportOptions(),
        );
        if ($init_files) {
            $ret->initProjectFiles();
            $ret->initExtraFiles();
        }
        return $ret;
    }

    public function testCheckFileReportsIssueWithoutInitialisedProjectFiles(): void
    {
        // Regression test: checkFile() is a single-file entry point that may run
        // without initProjectFiles() having populated $project_files. An issue
        // raised during the run reads $project_files (via canReportIssues), which
        // used to crash with "must not be accessed before initialization".
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
            false,
        );
        $this->project_analyzer->setPhpVersion('8.1', 'tests');

        $file_path = (string) getcwd()
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'DummyProject'
            . DIRECTORY_SEPARATOR . 'CheckFileRegression.php';

        $this->file_provider->registerFile(
            $file_path,
            '<?php

namespace Vimeo\Test\DummyProject;

function checkFileRegression(): int
{
    return "not an int";
}
',
        );

        // Must not throw; the issue in the file must still be reported.
        $this->project_analyzer->checkFile($file_path);

        $this->assertGreaterThan(0, IssueBuffer::getErrorCount());
    }

    public function testCheckDirReportsIssueWithoutInitialisedProjectFiles(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
            false,
        );
        $this->project_analyzer->setPhpVersion('8.1', 'tests');

        $dir_path = (string) getcwd()
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'DummyProject';

        $this->file_provider->registerFile(
            $dir_path . DIRECTORY_SEPARATOR . 'CheckDirRegression.php',
            '<?php

namespace Vimeo\Test\DummyProject;

function checkDirRegression(): int
{
    return "not an int";
}
',
        );

        // Must not throw; the issue in the file must still be reported.
        $this->project_analyzer->checkDir($dir_path);

        $this->assertGreaterThan(0, IssueBuffer::getErrorCount());
    }

    public function testCheck(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
        );
        $this->project_analyzer->setPhpVersion('8.1', 'tests');

        $this->project_analyzer->progress = new EchoProgress();

        ob_start();
        $this->project_analyzer->check('tests/fixtures/DummyProject');
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Target PHP version: 8.1 (set by tests)', $output);
        $this->assertStringContainsString('Scanning files...', $output);
        $this->assertStringContainsString('Analyzing files...', $output);

        $this->assertSame(0, IssueBuffer::getErrorCount());

        $codebase = $this->project_analyzer->getCodebase();

        $this->assertSame([0, 5], $codebase->analyzer->getTotalTypeCoverage($codebase));

        $this->assertSame(
            'Psalm was able to infer types for 100% of the codebase',
            $codebase->analyzer->getTypeInferenceSummary(
                $codebase,
            ),
        );
    }

    #[DataProvider('entryPointProvider')]
    public function testAfterCodebasePopulatedIsInvoked(string $entry_point): void
    {
        $hook = new class implements AfterCodebasePopulatedInterface {
            public static bool $called = false;

            /**
             * @return void
             * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint
             * @psalm-external-mutation-free
             */
            #[Override]
            public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event)
            {
                self::$called = true;
            }
        };

        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
        );

        $hook_class = get_class($hook);

        $this->project_analyzer->getCodebase()->config->eventDispatcher->after_codebase_populated[] = $hook_class;
        $hook::$called = false;

        $file_path = (string) realpath((string) getcwd() . '/tests/fixtures/DummyProject/Bar.php');

        ob_start();
        match ($entry_point) {
            'check' => $this->project_analyzer->check('tests/fixtures/DummyProject'),
            'checkDir' => $this->project_analyzer->checkDir('tests/fixtures/DummyProject'),
            'checkFile' => $this->project_analyzer->checkFile($file_path),
            'checkPaths' => $this->project_analyzer->checkPaths([$file_path]),
        };
        ob_end_clean();

        $this->assertTrue($hook::$called);
    }

    /**
     * @return iterable<string, array{string}>
     * @psalm-pure
     */
    public static function entryPointProvider(): iterable
    {
        yield 'check' => ['check'];
        yield 'checkDir' => ['checkDir'];
        yield 'checkFile' => ['checkFile'];
        yield 'checkPaths' => ['checkPaths'];
    }

    public function testCheckAfterNoChange(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
        );

        $this->assertNotNull($this->project_analyzer->stdout_report_options);

        $this->project_analyzer->stdout_report_options->format = Report::TYPE_JSON;

        $this->project_analyzer->check('tests/fixtures/DummyProject', true);
        ob_start();
        IssueBuffer::finish($this->project_analyzer, true, microtime(true));
        ob_end_clean();

        $this->assertSame(
            'Psalm was able to infer types for 100% of the codebase',
            $this->project_analyzer->getCodebase()->analyzer->getTypeInferenceSummary(
                $this->project_analyzer->getCodebase(),
            ),
        );

        $this->project_analyzer->getCodebase()->reloadFiles($this->project_analyzer, []);

        $this->project_analyzer->check('tests/fixtures/DummyProject', true);

        $this->assertSame(0, IssueBuffer::getErrorCount());

        $this->assertSame(
            "No files analyzed\nPsalm was able to infer types for 100% of the codebase",
            $this->project_analyzer->getCodebase()->analyzer->getTypeInferenceSummary(
                $this->project_analyzer->getCodebase(),
            ),
        );
    }

    public function testCheckAfterFileChange(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
        );

        $this->assertNotNull($this->project_analyzer->stdout_report_options);

        $this->project_analyzer->stdout_report_options->format = Report::TYPE_JSON;

        $this->project_analyzer->check('tests/fixtures/DummyProject', true);
        ob_start();
        IssueBuffer::finish($this->project_analyzer, true, microtime(true));
        ob_end_clean();

        $this->assertSame(
            'Psalm was able to infer types for 100% of the codebase',
            $this->project_analyzer->getCodebase()->analyzer->getTypeInferenceSummary(
                $this->project_analyzer->getCodebase(),
            ),
        );

        $bat_file_path = (string) getcwd()
            . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'DummyProject'
            . DIRECTORY_SEPARATOR . 'Bat.php';

        $bat_replacement_contents = '<?php

namespace Vimeo\Test\DummyProject;

final class Bat
{
    public function __construct()
    {
        $a = new Bar();
    }
}
';

        $this->file_provider->registerFile($bat_file_path, $bat_replacement_contents);

        $this->project_analyzer->getCodebase()->reloadFiles($this->project_analyzer, []);

        $this->project_analyzer->check('tests/fixtures/DummyProject', true);

        $this->assertSame(0, IssueBuffer::getErrorCount());

        $this->assertSame(
            'Psalm was able to infer types for 100% of the codebase',
            $this->project_analyzer->getCodebase()->analyzer->getTypeInferenceSummary(
                $this->project_analyzer->getCodebase(),
            ),
        );
    }

    public function testCheckDir(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
        );
        $this->project_analyzer->initExtraFiles();
        $this->project_analyzer->initProjectFiles();

        $this->project_analyzer->setPhpVersion('8.1', 'tests');

        $this->project_analyzer->progress = new EchoProgress();

        ob_start();
        $this->project_analyzer->checkDir('tests/fixtures/DummyProject');
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Target PHP version: 8.1 (set by tests)', $output);
        $this->assertStringContainsString('Scanning files...', $output);
        $this->assertStringContainsString('Analyzing files...', $output);

        $this->assertSame(0, IssueBuffer::getErrorCount());

        $this->assertSame(
            'Psalm was able to infer types for 100% of the codebase',
            $this->project_analyzer->getCodebase()->analyzer->getTypeInferenceSummary(
                $this->project_analyzer->getCodebase(),
            ),
        );
    }

    public function testCheckPaths(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
        );

        $this->project_analyzer->setPhpVersion('8.1', 'tests');

        $this->project_analyzer->progress = new EchoProgress();

        ob_start();
        // checkPaths expects absolute paths,
        // otherwise it's unable to match them against configured folders
        $this->project_analyzer->checkPaths([
            (string) realpath((string) getcwd() . '/tests/fixtures/DummyProject/Bar.php'),
            (string) realpath((string) getcwd() . '/tests/fixtures/DummyProject/SomeTrait.php'),
        ]);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Target PHP version: 8.1 (set by tests)', $output);
        $this->assertStringContainsString('Scanning files...', $output);
        $this->assertStringContainsString('Analyzing files...', $output);

        $this->assertSame(0, IssueBuffer::getErrorCount());

        $this->assertSame(
            'Psalm was able to infer types for 100% of the codebase',
            $this->project_analyzer->getCodebase()->analyzer->getTypeInferenceSummary(
                $this->project_analyzer->getCodebase(),
            ),
        );
    }

    public function testCheckFile(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                </psalm>',
            ),
        );

        $this->project_analyzer->setPhpVersion('8.1', 'tests');

        $this->project_analyzer->progress = new EchoProgress();

        ob_start();
        // checkPaths expects absolute paths,
        // otherwise it's unable to match them against configured folders
        $this->project_analyzer->checkPaths([
            (string) realpath((string) getcwd() . '/tests/fixtures/DummyProject/Bar.php'),
            (string) realpath((string) getcwd() . '/tests/fixtures/DummyProject/SomeTrait.php'),
        ]);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Target PHP version: 8.1 (set by tests)', $output);
        $this->assertStringContainsString('Scanning files...', $output);
        $this->assertStringContainsString('Analyzing files...', $output);

        $this->assertSame(0, IssueBuffer::getErrorCount());

        $this->assertSame(
            'Psalm was able to infer types for 100% of the codebase',
            $this->project_analyzer->getCodebase()->analyzer->getTypeInferenceSummary(
                $this->project_analyzer->getCodebase(),
            ),
        );
    }

    public function testCheckInfersParamTypesOnlyForMethodsWhoseIssuesCanBeReported(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerForParamTypeInference();
        $fixable_before = IssueBuffer::getFixableIssues()['MissingParamType'] ?? 0;

        $this->project_analyzer->check('tests/fixtures/DummyProject');

        $possible_param_types = $this->project_analyzer->getCodebase()->analyzer->possible_method_param_types;
        $this->assertSame(
            "'hello'",
            $possible_param_types['vimeo\test\dummyproject\inferencetarget::project'][0]->getId(),
        );
        $this->assertArrayNotHasKey('vimeo\test\vendor\vendortarget::vendor', $possible_param_types);
        $this->assertSame($fixable_before + 1, IssueBuffer::getFixableIssues()['MissingParamType'] ?? 0);
    }

    public function testCheckPathsInfersParamTypesForAllMethods(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerForParamTypeInference();
        $codebase = $this->project_analyzer->getCodebase();
        $codebase->infer_types_from_usage = true;

        $this->project_analyzer->checkPaths([(string) getcwd() . '/tests/fixtures/DummyProject']);

        $this->assertSame(
            "'hello'",
            $codebase->analyzer->possible_method_param_types['vimeo\test\vendor\vendortarget::vendor'][0]->getId(),
        );
    }

    public function testCheckAltersParamTypesOnlyOfMethodsWhoseIssuesCanBeReported(): void
    {
        $this->project_analyzer = $this->getProjectAnalyzerForParamTypeInference();
        $this->project_analyzer->setIssuesToFix(['MissingParamType' => true]);
        $this->project_analyzer->alterCodeAfterCompletion(false, true);
        $vendor_file = (string) getcwd() . '/tests/fixtures/ExtraFiles/dependency/VendorTarget.php';
        $vendor_contents = $this->file_provider->getContents($vendor_file);

        $this->project_analyzer->check('tests/fixtures/DummyProject');

        $codebase = $this->project_analyzer->getCodebase();
        $this->assertStringContainsString(
            'public function project(string $a): void',
            $codebase->getFileContents((string) getcwd() . '/tests/fixtures/DummyProject/InferenceTarget.php'),
        );
        $this->assertSame($vendor_contents, $codebase->getFileContents($vendor_file));
    }

    private function getProjectAnalyzerForParamTypeInference(): ProjectAnalyzer
    {
        $this->file_provider->registerFile(
            (string) getcwd() . '/tests/fixtures/DummyProject/InferenceTarget.php',
            '<?php

namespace Vimeo\Test\DummyProject;

use Vimeo\Test\Vendor\VendorTarget;

final class InferenceTarget extends VendorTarget
{
    public function project($a): void {}
}

function callInferenceTargets(InferenceTarget $target, VendorTarget $vendor_target): void
{
    $target->project("hello");
    $target->vendor("hello");
    $vendor_target->vendor("hello");
}
',
        );
        $this->file_provider->registerFile(
            (string) getcwd() . '/tests/fixtures/ExtraFiles/dependency/VendorTarget.php',
            '<?php

namespace Vimeo\Test\Vendor;

class VendorTarget
{
    public function vendor($a): void {}
}
',
        );

        $project_analyzer = $this->getProjectAnalyzerWithConfig(
            Config::loadFromXML(
                (string)getcwd(),
                '<?xml version="1.0"?>
                <psalm>
                    <projectFiles>
                        <directory name="tests/fixtures/DummyProject" />
                    </projectFiles>
                    <extraFiles>
                        <directory name="tests/fixtures/ExtraFiles/dependency" />
                    </extraFiles>
                </psalm>',
            ),
        );
        $project_analyzer->setPhpVersion('8.1', 'tests');

        return $project_analyzer;
    }
}
