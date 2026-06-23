<?php

declare(strict_types=1);

namespace Psalm\Tests;

use Override;
use Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

final class Php85Test extends TestCase
{
    use InvalidCodeAnalysisTestTrait;
    use ValidCodeAnalysisTestTrait;

    #[Override]
    public function providerValidCodeParse(): iterable
    {
        return [
            'deprecatedCastSpellingsSilentBelowPhp85' => [
                'code' => '<?php
                    function f(mixed $x): array {
                        return [(boolean) $x, (integer) $x, (double) $x, (binary) $x];
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.4',
            ],
            'canonicalCastsAreNeverDeprecated' => [
                'code' => '<?php
                    function f(mixed $x): array {
                        return [(bool) $x, (int) $x, (float) $x, (string) $x];
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.5',
            ],
            'deprecatedFunctionsSilentBelowPhp85' => [
                'code' => '<?php
                    /** @param resource $stream */
                    function f(
                        \CurlHandle $curl,
                        \CurlShareHandle $share,
                        \XMLParser $parser,
                        $stream,
                        \mysqli_stmt $stmt
                    ): void {
                        curl_close($curl);
                        curl_share_close($share);
                        xml_parser_free($parser);
                        socket_set_timeout($stream, 1);
                        mysqli_execute($stmt);
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.4',
            ],
            'mhashConstantSilentBelowPhp85' => [
                'code' => '<?php
                    function f(): int {
                        return MHASH_MD5;
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.4',
            ],
            'deprecatedCastSuppressed' => [
                'code' => '<?php
                    function f(mixed $x): bool {
                        /** @psalm-suppress DeprecatedCast */
                        return (boolean) $x;
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.5',
            ],
            'deprecatedFunctionSuppressed' => [
                'code' => '<?php
                    function f(\CurlHandle $handle): void {
                        /** @psalm-suppress DeprecatedFunction */
                        curl_close($handle);
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.5',
            ],
            // The MHASH_* match is by name prefix, but it is guarded by the global
            // constant resolution, so a user-defined constant of the same shape must
            // not be flagged as a PHP deprecation.
            'userDefinedMhashConstantNotDeprecated' => [
                'code' => '<?php
                    const MHASH_CUSTOM = 1;
                    function f(): int {
                        return MHASH_CUSTOM;
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.5',
            ],
            // mysqli_execute_query is one underscore away from the deprecated
            // mysqli_execute; the exact-key lookup must leave it untouched.
            'mysqliExecuteQueryNotDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(\mysqli $conn): void {
                        mysqli_execute_query($conn, "SELECT 1");
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.5',
            ],
            // (real) is removed-in-8.0 syntax (php-parser Double KIND_REAL); it is
            // not the deprecated (double) spelling, so it must not raise DeprecatedCast.
            'realCastNotDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(mixed $x): float {
                        return (real) $x;
                    }',
                'assertions' => [],
                'ignored_issues' => [],
                'php_version' => '8.5',
            ],
        ];
    }

    #[Override]
    public function providerInvalidCodeParse(): iterable
    {
        return [
            'booleanCastDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(mixed $x): bool {
                        return (boolean) $x;
                    }',
                'error_message' => 'DeprecatedCast',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'integerCastDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(mixed $x): int {
                        return (integer) $x;
                    }',
                'error_message' => 'DeprecatedCast',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'doubleCastDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(mixed $x): float {
                        return (double) $x;
                    }',
                'error_message' => 'DeprecatedCast',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'binaryCastDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(mixed $x): string {
                        return (binary) $x;
                    }',
                'error_message' => 'DeprecatedCast',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'mysqliExecuteDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(\mysqli_stmt $stmt): void {
                        mysqli_execute($stmt);
                    }',
                'error_message' => 'is deprecated since PHP 8.5; use mysqli_stmt_execute instead',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'curlCloseDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(\CurlHandle $handle): void {
                        curl_close($handle);
                    }',
                'error_message' => 'DeprecatedFunction',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'curlShareCloseDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(\CurlShareHandle $handle): void {
                        curl_share_close($handle);
                    }',
                'error_message' => 'DeprecatedFunction',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'xmlParserFreeDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(\XMLParser $parser): void {
                        xml_parser_free($parser);
                    }',
                'error_message' => 'DeprecatedFunction',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'socketSetTimeoutDeprecatedOnPhp85' => [
                'code' => '<?php
                    /** @param resource $stream */
                    function f($stream): void {
                        socket_set_timeout($stream, 1);
                    }',
                'error_message' => 'DeprecatedFunction',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
            'mhashConstantDeprecatedOnPhp85' => [
                'code' => '<?php
                    function f(): int {
                        return MHASH_MD5;
                    }',
                'error_message' => 'DeprecatedConstant',
                'error_levels' => [],
                'php_version' => '8.5',
            ],
        ];
    }
}
