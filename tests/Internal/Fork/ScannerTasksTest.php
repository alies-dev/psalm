<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal\Fork;

use Amp\NullCancellation;
use Amp\Sync\Channel;
use Psalm\Internal\Fork\InitScannerTask;
use Psalm\Internal\Fork\ShutdownScannerTask;
use Psalm\Storage\FunctionStorage;
use Psalm\Tests\TestCase;
use Psalm\Type;

use function Amp\Sync\createChannelPair;

final class ScannerTasksTest extends TestCase
{
    /**
     * A forked scanner worker starts with the stubs the parent has, which the parent does not need back: it
     * reports only what the files it scanned registered (see Scanner::getGlobalRegistrations()).
     */
    public function testWorkerReportsNoGlobalItDidNotRegisterWhileScanning(): void
    {
        $codebase = $this->project_analyzer->getCodebase();
        /** @var Channel<void, void> $channel */
        [$channel] = createChannelPair();

        $codebase->functions->addGlobalFunction('inherited_function', new FunctionStorage());
        $codebase->addGlobalConstantType('INHERITED_CONSTANT', Type::getInt());

        (new InitScannerTask())->run($channel, new NullCancellation());

        $result = (new ShutdownScannerTask())->run($channel, new NullCancellation());

        $this->assertSame([], $result['global_registrations']);
    }
}
