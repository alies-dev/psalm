<?php

declare(strict_types=1);

namespace Psalm\Tests;

use Closure;
use Override;
use Psalm\Context;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Provider\Providers;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\PluginRegistrationSocket;
use Psalm\Progress\Phase;
use Psalm\Progress\Progress;
use Psalm\Tests\Internal\Provider\FakeParserCacheProvider;

use const DIRECTORY_SEPARATOR;

/**
 * The initialization pass of a class that inherits its constructor from a class in a file where no issue is
 * reported skips the bodies of that constructor when they can't initialize a property it checks.
 */
final class PropertyInitializationVendorParentTest extends TestCase
{
    private const SKIP_MESSAGE = 'Skipping the bodies of FakeVendor\Base::__construct in the initialization of App\Child';

    /** @var list<string> */
    private array $debug_messages = [];

    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $progress = new class (function (string $message): void {
            $this->debug_messages[] = $message;
        }) extends Progress {
            /**
             * @param Closure(string):void $on_debug
             */
            public function __construct(private readonly Closure $on_debug)
            {
            }

            #[Override]
            public function debug(string $message): void
            {
                ($this->on_debug)($message);
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

        $this->project_analyzer = new ProjectAnalyzer(
            $this->testConfig,
            new Providers($this->file_provider, new FakeParserCacheProvider()),
            progress: $progress,
        );
        $this->project_analyzer->setPhpVersion('8.0', 'tests');
        $this->project_analyzer->getConfig()->throw_exception = false;
    }

    public function testInertConstructorIsSkipped(): void
    {
        $this->addVendorFile('
            abstract class Base
            {
                private string $name;
                private array $data = [];
                private array $provided = [];

                public function __construct(string $name)
                {
                    $this->name = $name;

                    if (is_callable($this->sortId(), true)) {
                        $this->provided = [new Dependency($this->sortId())];
                    }
                }

                final public function sortId(): string
                {
                    $id = $this->name;

                    if (!str_contains($id, "::")) {
                        $id = static::class . "::" . $id;
                    }

                    if ($this->usesDataProvider()) {
                        $id .= " with data";
                    }

                    return $id;
                }

                final public function usesDataProvider(): bool
                {
                    return !empty($this->data);
                }
            }

            final class Dependency
            {
                public function __construct(public string $id)
                {
                }
            }
        ');

        $this->assertSame(
            ['Property App\Child::$subject is not defined in constructor of App\Child or in any private or final methods called in the constructor'],
            $this->analyzeChild('
                private string $subject;
            '),
        );
        $this->assertContains(self::SKIP_MESSAGE . "\n", $this->debug_messages);
    }

    public function testConstructorInitializingAPropertyOfTheChildIsAnalysed(): void
    {
        $this->addVendorFile('
            abstract class Base
            {
                public function __construct()
                {
                    $this->label = "label";
                }
            }
        ');

        $this->assertSame(
            ['Property App\Child::$other is not defined in constructor of App\Child or in any methods called in the constructor'],
            $this->analyzeChild('
                protected string $label;
                protected string $other;
            '),
        );
        $this->assertNotContains(self::SKIP_MESSAGE . "\n", $this->debug_messages);
    }

    public function testConstructorPassingThisAwayIsAnalysed(): void
    {
        $this->addVendorFile('
            abstract class Base
            {
                public function __construct()
                {
                    Registry::register($this);
                }
            }

            final class Registry
            {
                public static function register(object $object): void
                {
                }
            }
        ');

        $this->assertSame(
            ['Property App\Child::$subject is not defined in constructor of App\Child or in any private or final methods called in the constructor'],
            $this->analyzeChild('
                private string $subject;
            '),
        );
        $this->assertNotContains(self::SKIP_MESSAGE . "\n", $this->debug_messages);
    }

    public function testConstructorCallingAMethodOfTheChildIsAnalysed(): void
    {
        $this->addVendorFile('
            abstract class Base
            {
                public function __construct()
                {
                    $this->boot();
                }

                protected function boot(): void
                {
                }
            }
        ');

        $this->assertSame(
            [],
            $this->analyzeChild('
                protected int $value;

                protected function boot(): void
                {
                    $this->value = 1;
                }
            '),
        );
        $this->assertNotContains(self::SKIP_MESSAGE . "\n", $this->debug_messages);
    }

    public function testConstructorIsAnalysedForPluginHooks(): void
    {
        $this->addVendorFile('
            abstract class Base
            {
                public function __construct()
                {
                    $this->boot();
                }

                final public function boot(): void
                {
                }
            }
        ');

        $handler = new class implements AfterMethodCallAnalysisInterface {
            /** @var list<string> */
            public static array $method_ids = [];

            #[Override]
            public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
            {
                self::$method_ids[] = $event->getDeclaringMethodId();
            }
        };

        $codebase = $this->project_analyzer->getCodebase();
        (new PluginRegistrationSocket($codebase->config, $codebase))->registerHooksFromClass($handler::class);

        $this->assertSame(
            ['Property App\Child::$subject is not defined in constructor of App\Child or in any private or final methods called in the constructor'],
            $this->analyzeChild('
                private string $subject;
            '),
        );
        $this->assertNotContains(self::SKIP_MESSAGE . "\n", $this->debug_messages);
        $this->assertContains('FakeVendor\Base::boot', $handler::$method_ids);
    }

    private function addVendorFile(string $classes): void
    {
        $this->addFile(
            self::$src_dir_path . 'Psalm' . DIRECTORY_SEPARATOR . 'Internal' . DIRECTORY_SEPARATOR . 'Stubs'
                . DIRECTORY_SEPARATOR . 'FakeVendor.php',
            "<?php\nnamespace FakeVendor;\n" . $classes,
        );
    }

    /**
     * @return list<string> the messages of the PropertyNotSetInConstructor issues
     */
    private function analyzeChild(string $members): array
    {
        $file_path = self::$src_dir_path . 'somefile.php';

        $this->addFile(
            $file_path,
            "<?php\nnamespace App;\nfinal class Child extends \\FakeVendor\\Base\n{\n" . $members . "\n}\n",
        );

        $this->analyzeFile($file_path, new Context());

        $messages = [];

        foreach (IssueBuffer::getIssuesDataForFile($file_path) as $issue) {
            if ($issue->type === 'PropertyNotSetInConstructor') {
                $messages[] = $issue->message;
            }
        }

        return $messages;
    }
}
