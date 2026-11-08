<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal\Codebase;

use Psalm\Tests\TestCase;

/**
 * A scanning process sends back only what changed since it was forked: the parent merging that ends up as if it had
 * merged everything the process knows.
 */
final class ThreadDataSinceForkTest extends TestCase
{
    public function testClassLikesMergeTheChangesLikeEverything(): void
    {
        $parent = $this->project_analyzer->getCodebase()->classlikes;
        $parent->registerMissingClassLike('lib\lazy');
        $parent->registerMissingClassLike('lib\other');
        $parent->addFullyQualifiedClassName('Lib\Old');

        $process = clone $parent;
        $inherited = $process->getThreadData();
        // found after all: the inherited entry turns true
        $process->addFullyQualifiedClassName('Lib\Lazy');
        $process->addFullyQualifiedInterfaceName('Lib\Fresh');

        // another process found this one, merged before
        $parent->addFullyQualifiedClassName('Lib\Other');

        $expected = clone $parent;
        $expected->addThreadData($process->getThreadData());

        $parent->addThreadData($process->getThreadDataSince($inherited));

        self::assertEquals($expected->getThreadData(), $parent->getThreadData());
        self::assertFalse($parent->isMissingClassLike('lib\lazy'));
        self::assertFalse($parent->isMissingClassLike('lib\other'));
    }

    public function testScannerMergesTheChangesLikeEverything(): void
    {
        $parent = $this->project_analyzer->getCodebase()->scanner;
        $parent->addThreadData([[], [], [], [], [], [], [], ['/src/a.php' => false, '/src/c.php' => false], []]);
        $parent->setClassLikeFilePath('lib\a', '/src/a.php');

        $process = clone $parent;
        $inherited = $process->getThreadData();
        // deep-scanned after the shallow scan the process inherited
        $process->addThreadData([[], [], [], [], [], [], [], ['/src/a.php' => true], []]);
        $process->setClassLikeFilePath('lib\b', '/src/b.php');
        $process->queueClassLikeForScanning('Lib\C', true);

        // another process deep-scanned this one, merged before
        $parent->addThreadData([[], [], [], [], [], [], [], ['/src/c.php' => true], []]);

        $expected = clone $parent;
        $expected->addThreadData($process->getThreadData());

        $parent->addThreadData($process->getThreadDataSince($inherited, []));

        self::assertEquals($expected->getThreadData(), $parent->getThreadData());
        self::assertTrue($parent->getThreadData()[7]['/src/a.php']);
        self::assertTrue($parent->getThreadData()[7]['/src/c.php']);
    }
}
