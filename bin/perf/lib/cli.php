<?php

declare(strict_types=1);

/**
 * Parses `--name value`, `--name=value` and bare `--flag` arguments. Unlike getopt() it also works after a
 * leading subcommand word, which is not an option and would stop getopt().
 *
 * @param list<string> $args
 * @param list<string> $valued options that take a value
 * @param list<string> $flags options without a value
 * @return array<string, string|true>
 * @throws RuntimeException on unknown options, missing values or stray arguments
 */
function parseOptions(array $args, array $valued, array $flags = []): array
{
    $options = [];
    for ($i = 0, $n = count($args); $i < $n; $i++) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $args[$i], $m) !== 1) {
            throw new RuntimeException("unexpected argument '{$args[$i]}'");
        }

        $name = $m[1];
        if (in_array($name, $flags, true) && !isset($m[2])) {
            $options[$name] = true;
            continue;
        }

        if (!in_array($name, $valued, true)) {
            throw new RuntimeException("unknown option --{$name}");
        }

        if (isset($m[2])) {
            $options[$name] = $m[2];
            continue;
        }

        if (!isset($args[$i + 1])) {
            throw new RuntimeException("option --{$name} needs a value");
        }

        $options[$name] = $args[++$i];
    }

    return $options;
}

/**
 * @param array<string, string|true> $options
 * @param list<string> $names
 * @throws RuntimeException
 */
function requireOptions(array $options, array $names): void
{
    foreach ($names as $name) {
        if (!isset($options[$name]) || $options[$name] === true || $options[$name] === '') {
            throw new RuntimeException("missing --{$name}");
        }
    }
}

/**
 * @return array<mixed>
 * @throws RuntimeException
 */
function readJsonFile(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    if ($raw === false) {
        throw new RuntimeException("cannot read {$path}");
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("{$path} is not valid JSON: {$e->getMessage()}");
    }

    if (!is_array($data)) {
        throw new RuntimeException("{$path} does not hold a JSON object or array");
    }

    return $data;
}

/**
 * @param array<mixed> $data
 * @throws RuntimeException
 */
function writeJsonFile(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create directory {$dir}");
    }

    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    $json = json_encode($data, $flags);
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException("cannot write {$path}");
    }
}

function isSha1(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{40}$/', $value) === 1;
}

function isSha256(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
}

/** Project and shard names end up in file names, CSV lists and matrix entries. */
function isSafeName(mixed $value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1;
}
