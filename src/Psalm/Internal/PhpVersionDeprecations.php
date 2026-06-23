<?php

declare(strict_types=1);

namespace Psalm\Internal;

use function intdiv;
use function str_starts_with;
use function strtolower;

/**
 * Registry of internal functions and constants that PHP marks as deprecated
 * starting from a specific version.
 *
 * PHP emits these `E_DEPRECATED` notices only from the relevant version onwards,
 * so the deprecation must be reported only when the analysis target's php
 * version is at or above the version that introduced it. A stub `@deprecated`
 * docblock cannot express this (it is version blind and would fire on older
 * targets too), which is why the data lives here and is consulted directly by
 * the analyzers.
 *
 * @internal
 */
final class PhpVersionDeprecations
{
    /**
     * Lower-cased function id => deprecation metadata.
     *
     * Entries must not also be marked `@deprecated` in a bundled stub: that would
     * make both this registry and the stub-driven path in FunctionCallAnalyzer
     * report the same call. These functions are CallMap only, so they never are.
     *
     * @var array<string, array{since: int, replacement: non-empty-string|null}>
     */
    private const DEPRECATED_FUNCTIONS = [
        'curl_close' => ['since' => 8_05_00, 'replacement' => null],
        'curl_share_close' => ['since' => 8_05_00, 'replacement' => null],
        'mysqli_execute' => ['since' => 8_05_00, 'replacement' => 'mysqli_stmt_execute'],
        'socket_set_timeout' => ['since' => 8_05_00, 'replacement' => 'stream_set_timeout'],
        'xml_parser_free' => ['since' => 8_05_00, 'replacement' => null],
        // Deliberately omitted from the 8.5 set for now:
        // - finfo_close, imagedestroy: listed by the 8.5 deprecations RFC but not
        //   yet confirmed on php.watch, so left out to avoid false positives.
        // - get_defined_functions: only deprecated when the $exclude_disabled
        //   argument is passed, which needs per-call argument inspection.
        // - SplObjectStorage::contains()/attach()/detach(), Reflection*::setAccessible(),
        //   ReflectionParameter::allowsNull(): method calls, a separate analysis path.
    ];

    /**
     * Whole constant families (matched by name prefix) => php_version_id that first deprecates them.
     *
     * The `MHASH_*` family is provided by ext-hash and is deprecated in PHP 8.5.
     *
     * @var array<non-empty-string, int>
     */
    private const DEPRECATED_CONSTANT_PREFIXES = [
        'MHASH_' => 8_05_00,
    ];

    public static function getDeprecatedFunctionMessage(
        string $function_id,
        int $analysis_php_version_id,
    ): ?string {
        // PHP function names are case insensitive; the registry keys are lower-cased.
        $deprecation = self::DEPRECATED_FUNCTIONS[strtolower($function_id)] ?? null;

        if ($deprecation === null || $analysis_php_version_id < $deprecation['since']) {
            return null;
        }

        $message = 'The function ' . $function_id . ' is deprecated since PHP '
            . self::versionIdToString($deprecation['since']);

        if ($deprecation['replacement'] !== null) {
            $message .= '; use ' . $deprecation['replacement'] . ' instead';
        }

        return $message;
    }

    public static function getDeprecatedConstantMessage(
        string $constant_name,
        int $analysis_php_version_id,
    ): ?string {
        foreach (self::DEPRECATED_CONSTANT_PREFIXES as $prefix => $since) {
            if ($analysis_php_version_id >= $since && str_starts_with($constant_name, $prefix)) {
                return 'The constant ' . $constant_name . ' is deprecated since PHP '
                    . self::versionIdToString($since);
            }
        }

        return null;
    }

    private static function versionIdToString(int $version_id): string
    {
        return intdiv($version_id, 10_000) . '.' . intdiv($version_id % 10_000, 100);
    }
}
