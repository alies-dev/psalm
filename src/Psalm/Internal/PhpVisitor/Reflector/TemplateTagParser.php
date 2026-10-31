<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor\Reflector;

use Psalm\Exception\DocblockParseException;
use Psalm\Exception\IncorrectDocblockException;
use Psalm\Internal\Analyzer\CommentAnalyzer;

use function array_slice;
use function implode;
use function ltrim;
use function preg_match;
use function preg_split;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Parses the value of a `@template` tag: `Name [of|as|super Bound] [= Default] [description]`.
 * `=` starts a default only right after the name or the bound, so a description may contain one.
 *
 * @internal
 */
final class TemplateTagParser
{
    /**
     * @return array{name: string, modifier: ?string, bound: ?string, default: ?string}
     * @throws IncorrectDocblockException
     * @psalm-pure
     */
    public static function parse(string $line, string $tag = 'template'): array
    {
        $parts = preg_split('/\s+/', CommentAnalyzer::sanitizeDocblockType($line), 2) ?: [];
        $name = $parts[0] ?? '';
        $rest = $parts[1] ?? '';

        $glued = strpos($name, '=');
        if ($glued !== false) {
            $rest = trim(substr($name, $glued) . ' ' . $rest);
            $name = substr($name, 0, $glued);
        }

        if ($name === '') {
            throw new IncorrectDocblockException('Empty @' . $tag . ' tag');
        }

        $modifier = null;
        $bound = null;

        if (preg_match('/^(as|super|of)\s+(\S.*)$/is', $rest, $matches)) {
            $modifier = strtolower($matches[1]);

            try {
                [$bound, $rest] = self::splitType($matches[2]);
            } catch (DocblockParseException) {
                // reported when the bound is parsed
                return ['name' => $name, 'modifier' => $modifier, 'bound' => $matches[2], 'default' => null];
            }

            $glued = self::findTopLevelEquals($bound);
            if ($glued !== null) {
                $rest = trim(substr($bound, $glued) . ' ' . $rest);
                $bound = substr($bound, 0, $glued);
            }
        }

        $default = null;

        if ($rest !== '' && $rest[0] === '=') {
            $default = ltrim(substr($rest, 1));

            if ($default === '') {
                throw new IncorrectDocblockException('Empty default in @' . $tag . ' tag');
            }

            try {
                $default = self::splitType($default)[0];
            } catch (DocblockParseException) {
                // reported when the default is parsed
            }
        }

        return ['name' => $name, 'modifier' => $modifier, 'bound' => $bound, 'default' => $default];
    }

    /**
     * @return array{string, string} the type at the start of $text and what follows it
     * @throws DocblockParseException
     * @psalm-pure
     */
    private static function splitType(string $text): array
    {
        $parts = CommentAnalyzer::splitDocLine($text);

        return [$parts[0], implode(' ', array_slice($parts, 1))];
    }

    /** @psalm-pure */
    private static function findTopLevelEquals(string $type): ?int
    {
        $depth = 0;
        $quote = null;

        for ($i = 0, $l = strlen($type); $i < $l; ++$i) {
            $char = $type[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    ++$i;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '<' || $char === '(' || $char === '[' || $char === '{') {
                ++$depth;
            } elseif ($char === '>' || $char === ')' || $char === ']' || $char === '}') {
                --$depth;
            } elseif ($char === '=' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
