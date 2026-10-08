<?php

declare(strict_types=1);

namespace Psalm\Progress;

use Override;

use function ceil;
use function count;
use function function_exists;
use function getenv;
use function hrtime;
use function implode;
use function in_array;
use function is_callable;
use function is_int;
use function is_string;
use function max;
use function microtime;
use function number_format;
use function pcntl_alarm;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function round;
use function sprintf;
use function str_repeat;
use function strlen;

use const SIGALRM;
use const SIG_DFL;

/**
 * Interactive progress: one status line redrawn in place while a phase runs,
 * replaced by a summary when the phase ends.
 *
 * The status line is redrawn when tasks complete, and once a second by a SIGALRM
 * ticker where pcntl is available, so the elapsed time keeps moving even while
 * Psalm works on something that doesn't report progress.
 *
 * @api
 */
class DefaultProgress extends LongProgress
{
    // TODO(demo): remove the style switch once a style is chosen
    private const STYLE_ENV = 'PSALM_PROGRESS_STYLE';
    private const STYLE_LINES = 'lines';
    private const STYLE_TABLE = 'table';
    private const STYLE_COMPACT = 'compact';
    private const STYLE_MINIMAL = 'minimal';
    private const STYLE_TIMELINE = 'timeline';
    private const STYLE_ETA = 'eta';
    private const STYLES = [
        self::STYLE_LINES,
        self::STYLE_TABLE,
        self::STYLE_COMPACT,
        self::STYLE_MINIMAL,
        self::STYLE_TIMELINE,
        self::STYLE_ETA,
    ];

    private const SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const ASCII_SPINNER_FRAMES = ['|', '/', '-', '\\'];

    private const BAR_WIDTH = 20;

    private const TIMELINE_WIDTH = 24;

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

    private ?string $style = null;

    private ?float $run_started = null;

    /** @var list<array{Phase, int, float}> phase, tasks, seconds */
    private array $phase_stats = [];

    private int $spinner_frame = 0;

    private ?float $first_task_at = null;

    private int $max_threads = 1;

    /**
     * Clears the status line before anything else is written, so other messages
     * (e.g. warnings about slow files) don't get mixed with it.
     */
    #[Override]
    public function write(string $message): void
    {
        ++$this->busy;
        try {
            if (!$this->drawing_status) {
                $this->clearStatus();
            }

            parent::write($message);
        } finally {
            --$this->busy;
        }
    }

    #[Override]
    public function finish(): void
    {
        parent::finish();
        $this->disarmTicker();

        if ($this->run_started !== null) {
            switch ($this->getStyle()) {
                case self::STYLE_COMPACT:
                    $this->writeLine($this->getCompactSummary());
                    break;
                case self::STYLE_TIMELINE:
                    foreach ($this->getTimeline() as $line) {
                        $this->writeLine($line);
                    }
                    break;
            }
        }

        $this->run_started = null;
        $this->phase_stats = [];
        $this->max_threads = 1;
    }

    #[Override]
    protected function phaseStarted(): void
    {
        $this->run_started ??= microtime(true);
        $this->first_task_at = null;
        $this->max_threads = max($this->max_threads, $this->threads);
        $this->last_refresh = hrtime(true);
        $this->drawStatus();
        $this->armTicker();
    }

    #[Override]
    protected function reportTask(int $level): void
    {
        if ($this->progress === 1) {
            $this->first_task_at = microtime(true);
        }

        $now = hrtime(true);
        if ($now - $this->last_refresh < self::REFRESH_INTERVAL_NANOSECONDS) {
            return;
        }

        $this->last_refresh = $now;
        $this->drawStatus();
    }

    #[Override]
    protected function phaseEnded(Phase $phase): void
    {
        $this->clearStatus();

        $duration = microtime(true) - $this->started;
        $this->phase_stats[] = [$phase, $this->progress, $duration];

        if (!self::isWorthReporting($phase, $duration)) {
            return;
        }

        switch ($this->getStyle()) {
            case self::STYLE_LINES:
                parent::phaseEnded($phase);
                break;
            case self::STYLE_TABLE:
                $this->writeLine($this->getTableRow($phase, $duration));
                break;
            case self::STYLE_ETA:
                $this->writeLine($this->getRateSummary($phase, $duration));
                break;
        }
    }

    private function getStyle(): string
    {
        if ($this->style === null) {
            $style = getenv(self::STYLE_ENV);
            $this->style = is_string($style) && in_array($style, self::STYLES, true) ? $style : self::STYLE_TABLE;
        }

        return $this->style;
    }

    /**
     * @psalm-pure
     */
    private static function getPhaseName(Phase $phase): string
    {
        return match ($phase) {
            Phase::SCAN => 'Scan',
            Phase::ANALYSIS => 'Analysis',
            Phase::ALTERING => 'Fixes',
            Phase::TAINT_GRAPH_RESOLUTION => 'Taint graph',
            Phase::MERGING_THREAD_RESULTS => 'Merge',
            Phase::LOADING_CACHE => 'Cache',
            Phase::FINISHING => 'Finishing',
            Phase::JIT_COMPILATION, Phase::PRELOADING => 'Preload',
        };
    }

    /**
     * @psalm-pure
     */
    private static function getTaskCount(Phase $phase, int $tasks): string
    {
        return $phase === Phase::SCAN || $phase === Phase::ANALYSIS || $phase === Phase::ALTERING
            ? number_format($tasks) . ' files'
            : '';
    }

    /**
     * e.g. "✓ Analysis      8,629 files   21.3s  16 threads"
     */
    private function getTableRow(Phase $phase, float $duration): string
    {
        return sprintf(
            '%s %-12s %14s %8s%s',
            self::doesTerminalSupportUtf8() ? '✓' : '*',
            self::getPhaseName($phase),
            self::getTaskCount($phase, $this->progress),
            number_format($duration, 1) . 's',
            $this->threads > 1 ? "  {$this->threads} threads" : '',
        );
    }

    /**
     * e.g. "✓ Analyzed 8,629 files in 21.3s (405 files/s, 16 threads)"
     */
    private function getRateSummary(Phase $phase, float $duration): string
    {
        $mark = self::doesTerminalSupportUtf8() ? '✓ ' : '* ';
        $took = number_format($duration, 1) . 's';
        $details = [];

        $verb = match ($phase) {
            Phase::SCAN => 'Scanned',
            Phase::ANALYSIS => 'Analyzed',
            Phase::ALTERING => 'Processed',
            default => null,
        };

        if ($verb !== null && $duration > 0.0) {
            $details[] = number_format((float) $this->progress / $duration) . ' files/s';
        }

        if ($this->threads > 1) {
            $details[] = $this->threads . ' threads';
        }

        $suffix = $details ? ' (' . implode(', ', $details) . ')' : '';

        if ($verb !== null) {
            return $mark . $verb . ' ' . number_format($this->progress) . ' files in ' . $took . $suffix;
        }

        return $mark . match ($phase) {
            Phase::TAINT_GRAPH_RESOLUTION => 'Resolved taint graph',
            Phase::LOADING_CACHE => 'Loaded cached results',
            Phase::FINISHING => 'Finished up',
            default => 'Merged thread results',
        } . ' in ' . $took . $suffix;
    }

    /**
     * e.g. "Analyzed 8,629 files (19,171 scanned) in 76.0s using 16 threads"
     */
    private function getCompactSummary(): string
    {
        $analyzed = null;
        $scanned = null;
        foreach ($this->phase_stats as [$phase, $tasks]) {
            if ($phase === Phase::ANALYSIS) {
                $analyzed = $tasks;
            } elseif ($phase === Phase::SCAN) {
                $scanned = $tasks;
            }
        }

        $parts = [];
        if ($analyzed !== null) {
            $parts[] = 'Analyzed ' . number_format($analyzed) . ' files';
        }

        if ($scanned !== null) {
            $parts[] = '(' . number_format($scanned) . ' scanned)';
        }

        $parts[] = 'in ' . number_format(microtime(true) - (float) $this->run_started, 1) . 's';

        if ($this->max_threads > 1) {
            $parts[] = 'using ' . $this->max_threads . ' threads';
        }

        return implode(' ', $parts);
    }

    /**
     * One row per phase with its share of the total time
     *
     * @return list<string>
     */
    private function getTimeline(): array
    {
        $rows = [];
        $total = 0.0;
        foreach ($this->phase_stats as [$phase, $tasks, $duration]) {
            if (self::isWorthReporting($phase, $duration)) {
                $rows[] = [$phase, $tasks, $duration];
                $total += $duration;
            }
        }

        $lines = [];
        foreach ($rows as [$phase, $tasks, $duration]) {
            $share = $total > 0.0 ? $duration / $total : 0.0;
            $lines[] = sprintf(
                '%-12s %14s %8s  %s %3d%%',
                self::getPhaseName($phase),
                self::getTaskCount($phase, $tasks),
                number_format($duration, 1) . 's',
                self::renderInnerProgressBar(self::TIMELINE_WIDTH, $share),
                (int) round($share * 100.0),
            );
        }

        $lines[] = sprintf(
            '%-12s %14s %8s%s',
            'Total',
            '',
            number_format($total, 1) . 's',
            $this->max_threads > 1 ? "  {$this->max_threads} threads" : '',
        );

        return $lines;
    }

    private function drawStatus(): void
    {
        ++$this->busy;
        try {
            $label = $this->getLabel();
            $status = $this->getStatus();

            $line = '';
            $width = 0;

            if ($this->getStyle() === self::STYLE_ETA) {
                $frames = self::doesTerminalSupportUtf8() ? self::SPINNER_FRAMES : self::ASCII_SPINNER_FRAMES;
                $line .= $frames[$this->spinner_frame++ % count($frames)] . ' ';
                $width += 2;
                $status .= $this->getEstimate();
            }

            $line .= $label . ' ';
            $width += strlen($label) + 1 + strlen($status);

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

    /**
     * e.g. ", ~12s left", once there's enough progress to extrapolate from.
     * The rate is measured from the first completed task, so setup time (e.g. forking workers) doesn't skew it.
     */
    private function getEstimate(): string
    {
        if ($this->first_task_at === null
            || !$this->fixed_size
            || $this->number_of_tasks === null
            || $this->progress < 2
            || $this->progress >= $this->number_of_tasks
        ) {
            return '';
        }

        $elapsed = microtime(true) - $this->first_task_at;
        if ($elapsed < 2.0) {
            return '';
        }

        $left = $elapsed / (float) ($this->progress - 1) * (float) ($this->number_of_tasks - $this->progress);

        return ', ~' . (int) ceil($left) . 's left';
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
