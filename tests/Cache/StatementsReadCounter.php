<?php

declare(strict_types=1);

namespace Psalm\Tests\Cache;

use Override;
use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

/** Counts the times the statements of a file are read from the parser cache or parsed */
final class StatementsReadCounter extends Progress
{
    public int $reads = 0;

    public function __construct(private readonly string $file_path)
    {
    }

    /**
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    #[Override]
    public function debug(string $message): void
    {
        if ($message === "Loading $this->file_path from the parser cache\n"
            || $message === "Parsing $this->file_path because the cache is absent or outdated\n"
        ) {
            $this->reads++;
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
}
