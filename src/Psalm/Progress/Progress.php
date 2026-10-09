<?php

declare(strict_types=1);

namespace Psalm\Progress;

use function error_reporting;
use function function_exists;
use function fwrite;
use function sapi_windows_cp_is_utf8;
use function stripos;

use const E_ERROR;
use const PHP_EOL;
use const PHP_OS;
use const STDERR;

/**
 * @api
 */
abstract class Progress
{
    public function setErrorReporting(): void
    {
        error_reporting(E_ERROR);
    }

    abstract public function debug(string $message): void;


    abstract public function startPhase(Phase $phase, int $threads = 1): void;

    abstract public function expand(int $number_of_tasks): void;

    abstract public function taskDone(int $level): void;

    abstract public function finish(): void;


    abstract public function alterFileDone(string $file_name): void;

    /**
     * Writes a message to the user. Psalm and plugins should write to the terminal through this method
     * (or warning()) rather than to STDERR directly, so the message doesn't get mixed with the progress output.
     */
    public function write(string $message): void
    {
        fwrite(STDERR, $message);
    }

    /**
     * Warns the user about something not related to a location in the code (e.g. a missing extension, a plugin
     * misconfiguration). Problems in the analyzed code should be reported as issues instead.
     */
    public function warning(string $message): void
    {
        $this->write('Warning: ' . $message . PHP_EOL);
    }

    /**
     * Called in a forked worker: from then on, the progress may keep what is written instead of writing it, for
     * the main process to write it (see takeWorkerOutput())
     *
     * @internal
     */
    public function startBufferingWorkerOutput(): void
    {
    }

    /**
     * Returns what was kept since startBufferingWorkerOutput() was called, and forgets it
     *
     * @internal
     */
    public function takeWorkerOutput(): string
    {
        return '';
    }

    final protected static function doesTerminalSupportUtf8(): bool
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            if (!function_exists('sapi_windows_cp_is_utf8') || !sapi_windows_cp_is_utf8()) {
                return false;
            }
        }

        return true;
    }
}
