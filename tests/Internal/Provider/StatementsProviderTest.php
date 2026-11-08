<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal\Provider;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use PhpParser\Node;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\FakeFileProvider;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\Internal\Provider\StatementsProvider;
use Psalm\IssueBuffer;
use Psalm\Tests\TestCase;

use function array_map;
use function extension_loaded;
use function ini_get;
use function ini_set;
use function str_repeat;

use const DIRECTORY_SEPARATOR;

/**
 * A vendor file, which the parser cache doesn't keep, is requested several times, as the analysis of the classes
 * extending it does: each request must get what parsing the file at that moment gives.
 */
final class StatementsProviderTest extends TestCase
{
    private const PHP_VERSION_ID = 8_02_00;

    /**
     * Lazy requests leave out the bodies of f, g and label: the non-empty ones of the methods of the classes and enums
     * at the top level or in a namespace
     */
    private const LAZY_FILE = '<?php
        namespace Acme;

        class Acme {
            public function f(): int { return 1; }
            public function g(): int { return 2; }
            public function none(): void {}
        }

        enum Suit {
            case Hearts;
            public function label(): string { return "hearts"; }
        }

        if (true) {
            class Conditional {
                public function conditional(): int { return 3; }
            }
        }

        function make(): object {
            return new class {
                public function anonymous(): int { return 4; }
            };
        }';

    private FakeFileProvider $files;

    private StatementsProvider $provider;

    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->files = new FakeFileProvider();
        $this->files->registerFile(self::vendorFilePath(), '<?php class Acme { public function f(): int { return 1; } }');
        $this->provider = new StatementsProvider($this->files, new FakeParserCacheProvider());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testEveryRequestGetsTheStatementsOfTheFile(bool $use_igbinary): void
    {
        self::useIgbinary($use_igbinary);

        for ($i = 0; $i < 4; $i++) {
            $this->assertEquals($this->parse(), $this->getStatements());
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testEveryRequestGetsStatementsOfItsOwn(bool $use_igbinary): void
    {
        self::useIgbinary($use_igbinary);
        $this->getStatements();
        $this->getStatements();

        $third = $this->getStatements();
        $this->assertInstanceOf(Class_::class, $third[0]);
        $third[0]->stmts = [];
        $fourth = $this->getStatements();

        $this->assertNotSame($third[0], $fourth[0]);
        $this->assertEquals($this->parse(), $fourth);
    }

    public function testRequestAfterAChangeGetsTheNewStatements(): void
    {
        $this->getStatements();
        $this->getStatements();

        $this->files->setContents(self::vendorFilePath(), '<?php class Acme { public function g(): void {} }');

        $this->assertEquals($this->parse(), $this->getStatements());
    }

    public function testRequestForAnotherPhpVersionGetsTheStatementsOfThatVersion(): void
    {
        $this->files->setContents(self::vendorFilePath(), '<?php enum Suit {}');
        $this->provider->getStatementsForFile(self::vendorFilePath(), 8_01_00, false);
        $enum = $this->provider->getStatementsForFile(self::vendorFilePath(), 8_01_00, false);

        // as ProjectAnalyzer::setPhpVersion() does
        StatementsProvider::clearParser();
        $stmts = $this->provider->getStatementsForFile(self::vendorFilePath(), 8_00_00, false);

        // enums don't exist before PHP 8.1
        $this->assertNotEquals($enum, $stmts);
        $this->assertEquals($this->parse(8_00_00), $stmts);
    }

    public function testFloatsComeBackExactWhateverTheSerializePrecision(): void
    {
        self::useIgbinary(false);
        $this->files->setContents(self::vendorFilePath(), '<?php return 1.000000000000001;');
        $precision = ini_set('serialize_precision', '14');

        try {
            $this->getStatements();
            $this->getStatements();
            $third = $this->getStatements();

            $this->assertSame('14', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $precision);
        }

        $this->assertInstanceOf(Return_::class, $third[0]);
        $this->assertInstanceOf(Float_::class, $third[0]->expr);
        $this->assertSame(1.000_000_000_000_001, $third[0]->expr->value);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testDeeplyNestedStatementsComeBackWhateverTheUnserializeDepthLimit(bool $use_igbinary): void
    {
        self::useIgbinary($use_igbinary);
        $this->files->setContents(self::vendorFilePath(), '<?php $a = "a"' . str_repeat(' . "a"', 120) . ';');
        $max_depth = ini_set('unserialize_max_depth', '16');

        try {
            $this->getStatements();
            $this->getStatements();
            $third = $this->getStatements();
        } finally {
            ini_set('unserialize_max_depth', (string) $max_depth);
        }

        $this->assertEquals($this->parse(), $third);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testStatementsTooDeepToSerializeComeBackToo(bool $use_igbinary): void
    {
        self::useIgbinary($use_igbinary);
        // serializing 10 000 nested concatenations runs out of stack
        $this->files->setContents(self::vendorFilePath(), '<?php $a = "a"' . str_repeat(' . "a"', 10_000) . ';');

        $this->getStatements();
        $this->getStatements();

        // assertEquals() takes minutes to compare trees this deep
        $this->assertTrue($this->parse() == $this->getStatements());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testLazyRequestGetsTheBodiesOfTheMethodsItHydrates(bool $use_igbinary): void
    {
        self::useIgbinary($use_igbinary);
        $this->files->setContents(self::vendorFilePath(), self::LAZY_FILE);
        $this->getStatements();
        $this->getStatements();

        $stmts = $this->getStatements(true);
        $parsed = $this->parse();

        foreach (['f', 'g', 'label'] as $name) {
            $this->assertSame([], self::getMethod($stmts, $name)->stmts);
        }

        foreach (['none', 'conditional', 'anonymous'] as $name) {
            $this->assertEquals(self::getMethod($parsed, $name), self::getMethod($stmts, $name));
        }

        StatementsProvider::hydrateMethodBody(self::getMethod($stmts, 'f'));

        $this->assertEquals(self::getMethod($parsed, 'f'), self::getMethod($stmts, 'f'));
        $this->assertSame([], self::getMethod($stmts, 'g')->stmts);
        $this->assertTrue(self::getMethod($stmts, 'g')->hasAttribute(StatementsProvider::LAZY_METHOD_BODY));

        StatementsProvider::hydrateMethodBody(self::getMethod($stmts, 'g'));
        StatementsProvider::hydrateMethodBody(self::getMethod($stmts, 'label'));

        $this->assertEquals($parsed, $stmts);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testLazyRequestsGetMethodBodiesOfTheirOwn(bool $use_igbinary): void
    {
        self::useIgbinary($use_igbinary);
        $this->files->setContents(self::vendorFilePath(), self::LAZY_FILE);
        $this->getStatements();
        $this->getStatements();

        $third = self::getMethod($this->getStatements(true), 'f');
        StatementsProvider::hydrateMethodBody($third);
        $this->assertNotNull($third->stmts);
        $third->stmts[0]->setAttribute('analysed', true);

        $fourth = self::getMethod($this->getStatements(true), 'f');
        StatementsProvider::hydrateMethodBody($fourth);

        $this->assertNotNull($fourth->stmts);
        $this->assertNotSame($third->stmts[0], $fourth->stmts[0]);
        $this->assertEquals(self::getMethod($this->parse(), 'f'), $fourth);
    }

    public function testLazyRequestOfStatementsNotKeptYetGetsEveryBody(): void
    {
        $this->files->setContents(self::vendorFilePath(), self::LAZY_FILE);

        $this->assertEquals($this->parse(), $this->getStatements(true));
        $this->assertEquals($this->parse(), $this->getStatements(true));
    }

    public function testAnalysingAMethodWhoseBodyWasLeftOutFails(): void
    {
        $this->addFile(self::vendorFilePath(), '<?php class Acme { public function f(): int { return 1; } }');
        $this->addFile('somefile.php', '<?php class Child extends Acme {}');
        $this->analyzeFile('somefile.php', new Context());

        $codebase = $this->project_analyzer->getCodebase();
        $codebase->getStatementsForFile(self::vendorFilePath());
        $codebase->getStatementsForFile(self::vendorFilePath());

        $file_analyzer = new FileAnalyzer($this->project_analyzer, self::vendorFilePath(), 'Acme.php');
        $file_analyzer->populateCheckers(
            $codebase->getStatementsForFile(self::vendorFilePath(), lazy_method_bodies: true),
        );
        $method_analyzer = $file_analyzer->getFunctionLikeAnalyzer(new MethodIdentifier('Acme', 'f'));
        $this->assertNotNull($method_analyzer);

        $this->expectException(LogicException::class);
        $method_analyzer->analyze(new Context('Acme'), new NodeDataProvider());
    }

    /**
     * The analysis of each class extending a vendor class runs the vendor constructor, with the methods it calls
     */
    public function testClassesExtendingAVendorClassGetWhatTheMethodsItsConstructorCallsInitialize(): void
    {
        $this->testConfig->throw_exception = false;
        $file_path = self::$src_dir_path . 'somefile.php';
        $this->addFile(
            self::vendorFilePath(),
            '<?php
                namespace Acme;

                use ArrayObject as Bag;

                class Base {
                    protected Bag $bag;
                    protected int $count;

                    public function __construct() {
                        $this->init();
                    }

                    private function init(): void {
                        $this->bag = new Bag();
                    }

                    public function count(): void {
                        $this->count = 1;
                    }
                }',
        );
        $this->addFile(
            $file_path,
            '<?php
                final class A extends Acme\Base {}
                final class B extends Acme\Base {}
                final class C extends Acme\Base {}
                final class D extends Acme\Base {}',
        );

        $this->analyzeFile($file_path, new Context());

        $this->assertSame(
            [
                [2, 'PropertyNotSetInConstructor', 'Property A::$count is not defined in constructor of A or in any methods called in the constructor'],
                [3, 'PropertyNotSetInConstructor', 'Property B::$count is not defined in constructor of B or in any methods called in the constructor'],
                [4, 'PropertyNotSetInConstructor', 'Property C::$count is not defined in constructor of C or in any methods called in the constructor'],
                [5, 'PropertyNotSetInConstructor', 'Property D::$count is not defined in constructor of D or in any methods called in the constructor'],
            ],
            array_map(
                static fn(IssueData $issue): array => [$issue->line_from, $issue->type, $issue->message],
                IssueBuffer::getIssuesDataForFile($file_path),
            ),
        );
    }

    /**
     * @param list<Stmt> $stmts
     */
    private static function getMethod(array $stmts, string $name): ClassMethod
    {
        $method = (new NodeFinder())->findFirst(
            $stmts,
            static fn(Node $node): bool => $node instanceof ClassMethod && $node->name->name === $name,
        );
        self::assertInstanceOf(ClassMethod::class, $method);

        return $method;
    }

    /**
     * @return list<Stmt>
     */
    private function getStatements(bool $lazy_method_bodies = false): array
    {
        return $this->provider->getStatementsForFile(
            self::vendorFilePath(),
            self::PHP_VERSION_ID,
            false,
            null,
            $lazy_method_bodies,
        );
    }

    /**
     * @return list<Stmt>
     */
    private function parse(int $php_version_id = self::PHP_VERSION_ID): array
    {
        $parse_errors = [];
        StatementsProvider::clearParser();

        return StatementsProvider::parseStatements(
            $this->files->getContents(self::vendorFilePath()),
            $php_version_id,
            $parse_errors,
        );
    }

    private static function useIgbinary(bool $use_igbinary): void
    {
        if ($use_igbinary && !extension_loaded('igbinary')) {
            self::markTestSkipped('igbinary is not loaded');
        }

        Config::getInstance()->use_igbinary = $use_igbinary;
    }

    /**
     * Outside the project directories and below a vendor directory: the parser cache doesn't keep this file
     *
     * @psalm-pure
     */
    private static function vendorFilePath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'Acme.php';
    }
}
