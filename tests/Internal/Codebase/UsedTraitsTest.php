<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal\Codebase;

use Psalm\Context;
use Psalm\Tests\TestCase;

/**
 * The main process decides on trait suggestions (MissingImmutableAnnotation) from whether a class uses the trait,
 * which the analysis of that class finds, possibly in a worker.
 */
final class UsedTraitsTest extends TestCase
{
    public function testTheTraitsAWorkerFindsUsedAreMarkedInTheMainProcess(): void
    {
        $codebase = $this->project_analyzer->getCodebase();
        $this->addFile('somefile.php', '<?php trait T {} final class A { use T; }');
        $this->analyzeFile('somefile.php', new Context());

        $used_traits = $codebase->analyzer->getUsedTraits();
        self::assertSame(['t' => true], $used_traits);

        // the storage of the main process, which the worker's analysis did not touch
        $trait_storage = $codebase->classlike_storage_provider->get('T');
        $trait_storage->trait_used = false;

        $codebase->analyzer->addUsedTraits($used_traits);

        self::assertTrue($trait_storage->trait_used);
    }
}
