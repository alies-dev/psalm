<?php

declare(strict_types=1);

namespace Psalm\Progress;

use Override;

use function function_exists;
use function hrtime;
use function is_callable;
use function is_int;
use function max;
use function number_format;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function sprintf;
use function str_ends_with;
use function str_repeat;
use function strlen;

use const SIGALRM;
use const SIG_DFL;

/**
 * Interactive progress: one status line redrawn in place while a phase runs,
 * replaced by a row of the phase table when the phase ends.
 *
 * The status line is redrawn when tasks complete, and once a second by a SIGALRM
 * ticker where pcntl is available, so the elapsed time keeps moving even while
 * Psalm works on something that doesn't report progress.
 *
 * @api
 */
class DefaultProgress extends LongProgress
{
    private const BAR_WIDTH = 20;

    // Redraw the status line at most once per 0.1 seconds.
    // This reduces flickering and the time spent writing to STDERR.
    private const REFRESH_INTERVAL_NANOSECONDS = 100_000_000;

    // The alarm fires every second; skip the redraw only if a task just redrew the line.
    private const TICK_INTERVAL_NANOSECONDS = 900_000_000;

    private int $last_refresh = 0;

    /** Visible width of the status line currently on screen, 0 if none */
    private int $status_width = 0;

    private bool $drawing_status = false;

    /** Non-zero while output is being written, so that the ticker doesn't interleave with it */
    private int $busy = 0;

    private bool $ticker_armed = false;

    /** @var int|callable */
    private mixed $previous_alarm_handler = SIG_DFL;

    private bool $previous_async_signals = false;

    /**
     * Other messages (e.g. warnings) are written above the status line: it's cleared first,
     * and drawn again below the message.
     */
    #[Override]
    public function write(string $message): void
    {
        if ($this->isBufferingWorkerOutput()) {
            parent::write($message);
            return;
        }

        ++$this->busy;
        try {
            if ($this->drawing_status) {
                parent::write($message);
                return;
            }

            $status_was_drawn = $this->status_width > 0;
            $this->clearStatus();

            parent::write($message);

            if ($status_was_drawn && str_ends_with($message, "\n")) {
                $this->drawStatus();
            }
        } finally {
            --$this->busy;
        }
    }

    #[Override]
    public function finish(): void
    {
        parent::finish();
        $this->disarmTicker();
    }

    #[Override]
    protected function phaseStarted(): void
    {
        $this->last_refresh = hrtime(true);
        $this->drawStatus();
        $this->armTicker();
    }

    #[Override]
    protected function reportTask(int $level): void
    {
        $now = hrtime(true);
        if ($now - $this->last_refresh < self::REFRESH_INTERVAL_NANOSECONDS) {
            return;
        }

        $this->last_refresh = $now;
        $this->drawStatus();
    }

    /**
     * e.g. "✓ Analysis      8,629 files   21.3s  16 threads"
     */
    #[Override]
    protected function phaseEnded(Phase $phase): void
    {
        $this->clearStatus();

        $duration = $this->getPhaseDuration();
        if (!self::isWorthReporting($phase, $duration)) {
            return;
        }

        $name = match ($phase) {
            Phase::SCAN => 'Scan',
            Phase::ANALYSIS => 'Analysis',
            Phase::ALTERING => 'Fixes',
            Phase::TAINT_GRAPH_RESOLUTION => 'Taint graph',
            Phase::MERGING_THREAD_RESULTS => 'Merge',
            Phase::LOADING_CACHE => 'Cache',
            Phase::FINISHING => 'Finishing',
            Phase::JIT_COMPILATION, Phase::PRELOADING => 'Preload',
        };

        $tasks = $phase === Phase::SCAN || $phase === Phase::ANALYSIS || $phase === Phase::ALTERING
            ? number_format($this->progress) . ' files'
            : '';

        $this->writeLine(sprintf(
            '%s %-12s %14s %8s%s',
            self::doesTerminalSupportUtf8() ? '✓' : '*',
            $name,
            $tasks,
            number_format($duration, 1) . 's',
            $this->threads > 1 ? "  {$this->threads} threads" : '',
        ));
    }

    private function drawStatus(): void
    {
        ++$this->busy;
        try {
            $label = $this->getLabel();
            $status = $this->getStatus();

            $line = $label . ' ';
            $width = strlen($label) + 1 + strlen($status);

            if ($this->fixed_size && $this->number_of_tasks > 0) {
                $line .= self::renderInnerProgressBar(self::BAR_WIDTH, $this->progress / $this->number_of_tasks)
                    . ' ';
                $width += self::BAR_WIDTH + 1;
            }

            $line .= $status;

            $this->drawing_status = true;
            $this->write("\r" . $line . str_repeat(' ', max(0, $this->status_width - $width)));
            $this->drawing_status = false;

            $this->status_width = $width;
        } finally {
            --$this->busy;
        }
    }

    private function clearStatus(): void
    {
        if ($this->status_width === 0) {
            return;
        }

        ++$this->busy;
        try {
            $width = $this->status_width;
            $this->status_width = 0;

            $this->drawing_status = true;
            $this->write("\r" . str_repeat(' ', $width) . "\r");
            $this->drawing_status = false;
        } finally {
            --$this->busy;
        }
    }

    /**
     * Psalm can spend many seconds without completing a task (e.g. populating the codebase
     * after the scan). A once-a-second alarm keeps the elapsed time moving meanwhile.
     *
     * The alarm is armed only after Psalm has restarted itself (it would survive exec),
     * and forked workers don't inherit it.
     */
    private function armTicker(): void
    {
        if ($this->ticker_armed
            || !function_exists('pcntl_alarm')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            return;
        }

        $this->ticker_armed = true;
        $previous_handler = pcntl_signal_get_handler(SIGALRM);
        $this->previous_alarm_handler = is_int($previous_handler) || is_callable($previous_handler)
            ? $previous_handler
            : SIG_DFL;
        $this->previous_async_signals = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, $this->tick(...));
        pcntl_alarm(1);
    }

    private function disarmTicker(): void
    {
        if (!$this->ticker_armed) {
            return;
        }

        $this->ticker_armed = false;
        pcntl_alarm(0);
        /** @psalm-suppress MixedArgumentTypeCoercion it was registered as a signal handler before */
        pcntl_signal(SIGALRM, $this->previous_alarm_handler);
        pcntl_async_signals($this->previous_async_signals);
    }

    private function tick(): void
    {
        if (!$this->ticker_armed) {
            return;
        }

        pcntl_alarm(1);

        if ($this->busy > 0 || $this->phase === null || self::isSilent($this->phase)) {
            return;
        }

        $now = hrtime(true);
        if ($now - $this->last_refresh < self::TICK_INTERVAL_NANOSECONDS) {
            return;
        }

        $this->last_refresh = $now;
        $this->drawStatus();
    }

    /**
     * Fully stolen from
     * https://github.com/phan/phan/blob/d61a624b1384ea220f39927d53fd656a65a75fac/src/Phan/CLI.php
     * Renders a unicode progress bar that goes from light (left) to dark (right)
     * The length in the console is the positive integer $length
     *
     * @see https://en.wikipedia.org/wiki/Block_Elements
     */
    private static function renderInnerProgressBar(int $length, float $p): string
    {
        $current_float = $p * (float) $length;
        $current = (int)$current_float;
        $rest = max($length - $current, 0);

        if (!self::doesTerminalSupportUtf8()) {
            // Show a progress bar of "XXXX>------" in Windows when utf-8 is unsupported.
            $progress_bar = str_repeat('X', $current);
            $delta = $current_float - (float) $current;
            if ($delta > 0.5) {
                $progress_bar .= '>' . str_repeat('-', $rest - 1);
            } else {
                $progress_bar .= str_repeat('-', $rest);
            }

            return $progress_bar;
        }

        // The left-most characters are "Light shade"
        $progress_bar = str_repeat("\u{2588}", $current);
        $delta = $current_float - (float) $current;
        if ($delta > 0.75) {
            $progress_bar .= "\u{258A}" . str_repeat("\u{2591}", $rest - 1);
        } elseif ($delta > 0.5) {
            $progress_bar .= "\u{258C}" . str_repeat("\u{2591}", $rest - 1);
        } elseif ($delta > 0.25) {
            $progress_bar .= "\u{258E}" . str_repeat("\u{2591}", $rest - 1);
        } else {
            $progress_bar .= str_repeat("\u{2591}", $rest);
        }

        return $progress_bar;
    }
}
