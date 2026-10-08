<?php

declare(strict_types=1);

namespace Psalm\Progress;

use LogicException;
use Override;

use function in_array;
use function intdiv;
use function microtime;
use function number_format;
use function sprintf;
use function str_repeat;
use function strlen;

use const PHP_EOL;

/**
 * Line-based progress output.
 *
 * In quiet mode (CI, or a stderr that isn't a terminal) every phase prints a start line,
 * a status line every few seconds so that a long run doesn't look stuck, and a summary line.
 * Otherwise (--long-progress) it prints a grid with a marker per task.
 *
 * @api
 */
class LongProgress extends Progress
{
    final public const NUMBER_OF_COLUMNS = 60;

    /** Seconds between status lines in quiet mode */
    private const STATUS_INTERVAL = 10.0;

    protected ?int $number_of_tasks = null;

    protected int $progress = 0;

    protected bool $fixed_size = false;

    /**
     * True when the current phase runs an unknown number of tasks (e.g. taint
     * graph resolution, which loops to a fixed point). A percentage is
     * meaningless in that case.
     */
    protected bool $indeterminate = false;

    protected ?Phase $phase = null;

    protected int $threads = 1;

    protected float $started = 0.0;

    private float $last_status = 0.0;

    /** Whether the grid left the cursor in the middle of a line */
    private bool $mid_line = false;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        protected bool $print_errors = true,
        protected bool $print_infos = true,
        protected bool $in_ci = false,
    ) {
    }

    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function debug(string $message): void
    {
    }

    #[Override]
    public function startPhase(Phase $phase, int $threads = 1): void
    {
        if ($phase === $this->phase) {
            return;
        }

        $this->endPhase();

        $this->phase = $phase;
        $this->threads = $threads;
        $this->progress = 0;
        $this->number_of_tasks = 0;
        $this->started = $this->last_status = microtime(true);
        $this->fixed_size = $phase !== Phase::SCAN && $phase !== Phase::TAINT_GRAPH_RESOLUTION;
        $this->indeterminate = $phase === Phase::TAINT_GRAPH_RESOLUTION;

        if (!self::isSilent($phase)) {
            $this->phaseStarted();
        }
    }

    #[Override]
    public function alterFileDone(string $file_name): void
    {
        $this->writeLine('Altered ' . $file_name);
    }

    /**
     * @psalm-external-mutation-free
     */
    #[Override]
    public function expand(int $number_of_tasks): void
    {
        $this->number_of_tasks += $number_of_tasks;
    }

    #[Override]
    public function taskDone(int $level): void
    {
        if ($this->number_of_tasks === null) {
            throw new LogicException('Progress::startPhase() should be called before Progress::taskDone()');
        }

        ++$this->progress;

        if ($this->phase !== null && !self::isSilent($this->phase)) {
            $this->reportTask($level);
        }
    }

    #[Override]
    public function finish(): void
    {
        $this->endPhase();
    }

    protected function phaseStarted(): void
    {
        $this->writeLine($this->getLabel() . '...');
    }

    protected function reportTask(int $level): void
    {
        if ($this->in_ci) {
            $now = microtime(true);
            if ($now - $this->last_status >= self::STATUS_INTERVAL) {
                $this->last_status = $now;
                $this->writeLine('  ' . $this->getStatus());
            }

            return;
        }

        if ($this->indeterminate) {
            $this->writeTick(self::doesTerminalSupportUtf8() ? '░' : '_');
            if (($this->progress % self::NUMBER_OF_COLUMNS) === 0) {
                $this->writeLine('');
            }

            return;
        }

        if (!$this->fixed_size) {
            if ($this->progress === 1 || $this->progress === $this->number_of_tasks || $this->progress % 10 === 0) {
                $this->writeTick(sprintf("\r%s / %s...", $this->progress, (int) $this->number_of_tasks));
            }

            return;
        }

        if ($level === 0 || ($level === 1 && !$this->print_infos) || !$this->print_errors) {
            $this->writeTick(self::doesTerminalSupportUtf8() ? '░' : '_');
        } elseif ($level === 1) {
            $this->writeTick('I');
        } else {
            $this->writeTick('E');
        }

        if (($this->progress % self::NUMBER_OF_COLUMNS) !== 0) {
            if ($this->progress !== $this->number_of_tasks) {
                return;
            }
            if ($this->number_of_tasks > self::NUMBER_OF_COLUMNS) {
                $this->write(str_repeat(' ', self::NUMBER_OF_COLUMNS - ($this->progress % self::NUMBER_OF_COLUMNS)));
            }
        }

        $this->writeLine($this->getOverview());
    }

    protected function phaseEnded(Phase $phase): void
    {
        $summary = $this->getSummary($phase);
        if ($summary !== null) {
            $this->writeLine($summary);
        }
    }

    /**
     * What the current phase is doing, e.g. "Analyzing files (16 threads)"
     *
     * @psalm-mutation-free
     */
    protected function getLabel(): string
    {
        $label = match ($this->phase) {
            Phase::SCAN => 'Scanning files',
            Phase::ANALYSIS => 'Analyzing files',
            Phase::ALTERING => 'Updating files',
            Phase::TAINT_GRAPH_RESOLUTION => 'Resolving taint graph',
            Phase::JIT_COMPILATION, Phase::PRELOADING => 'Preloading',
            Phase::MERGING_THREAD_RESULTS => 'Merging thread results',
            Phase::LOADING_CACHE => 'Loading cached results',
            Phase::FINISHING => 'Finishing',
            null => '',
        };

        return $label . $this->getThreadsSuffix();
    }

    /**
     * How far the current phase got, e.g. "4,320 / 8,629 files (50%), 12s"
     */
    protected function getStatus(): string
    {
        $elapsed = (int) (microtime(true) - $this->started) . 's';

        if ($this->indeterminate) {
            return ($this->progress > 0 ? 'pass ' . $this->progress . ', ' : '') . $elapsed;
        }

        if ($this->number_of_tasks === null || $this->number_of_tasks === 0) {
            return $elapsed;
        }

        $status = number_format($this->progress) . ' / ' . number_format($this->number_of_tasks)
            . ($this->phase === Phase::MERGING_THREAD_RESULTS ? ' threads' : ' files');

        if ($this->fixed_size) {
            $status .= ' (' . intdiv($this->progress * 100, $this->number_of_tasks) . '%)';
        }

        return $status . ', ' . $elapsed;
    }

    /**
     * @psalm-mutation-free
     */
    protected function getOverview(): string
    {
        if ($this->number_of_tasks === null) {
            throw new LogicException('Progress::startPhase() should be called before Progress::getOverview()');
        }

        $leadingSpaces = 1 + strlen((string) $this->number_of_tasks) - strlen((string) $this->progress);
        // Don't show 100% unless this is the last line of the progress bar.
        $percentage = $this->number_of_tasks > 0 ? intdiv($this->progress * 100, $this->number_of_tasks) : 0;

        return sprintf(
            '%s%s / %s (%s%%)',
            str_repeat(' ', $leadingSpaces),
            $this->progress,
            $this->number_of_tasks,
            $percentage,
        );
    }

    /**
     * Writes a full line, ending a line the grid left open first.
     */
    protected function writeLine(string $line): void
    {
        if ($this->mid_line) {
            $this->mid_line = false;
            $this->write(PHP_EOL);
        }

        $this->write($line . PHP_EOL);
    }

    /**
     * Preloading takes a fraction of a second and only concerns Psalm itself, so it isn't reported.
     *
     * @psalm-pure
     */
    protected static function isSilent(Phase $phase): bool
    {
        return $phase === Phase::PRELOADING || $phase === Phase::JIT_COMPILATION;
    }

    /**
     * Some phases are usually quick; they're only worth reporting when they aren't.
     *
     * @psalm-pure
     */
    protected static function isWorthReporting(Phase $phase, float $duration): bool
    {
        return $duration >= 1.0 || !in_array(
            $phase,
            [Phase::MERGING_THREAD_RESULTS, Phase::LOADING_CACHE, Phase::FINISHING],
            true,
        );
    }

    private function endPhase(): void
    {
        if ($this->phase === null) {
            return;
        }

        if (!self::isSilent($this->phase)) {
            $this->phaseEnded($this->phase);
        }

        $this->phase = null;
    }

    /**
     * Returns null for a phase that isn't worth reporting
     */
    private function getSummary(Phase $phase): ?string
    {
        $duration = microtime(true) - $this->started;
        if (!self::isWorthReporting($phase, $duration)) {
            return null;
        }

        $took = number_format($duration, 1) . 's';
        $tasks = number_format($this->progress);

        return match ($phase) {
            Phase::SCAN => "Scanned $tasks files in $took",
            Phase::ANALYSIS => "Analyzed $tasks files in $took",
            Phase::ALTERING => "Processed $tasks files in $took",
            Phase::TAINT_GRAPH_RESOLUTION => "Resolved taint graph in $took",
            Phase::JIT_COMPILATION, Phase::PRELOADING => "Preloaded in $took",
            Phase::MERGING_THREAD_RESULTS => "Merged thread results in $took",
            Phase::LOADING_CACHE => "Loaded cached results in $took",
            Phase::FINISHING => "Finished up in $took",
        } . $this->getThreadsSuffix();
    }

    /**
     * @psalm-mutation-free
     */
    private function getThreadsSuffix(): string
    {
        return $this->threads > 1 ? " ({$this->threads} threads)" : '';
    }

    private function writeTick(string $tick): void
    {
        $this->mid_line = true;
        $this->write($tick);
    }
}
