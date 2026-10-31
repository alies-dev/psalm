<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor\Reflector;

use Psalm\Aliases;
use Psalm\Exception\IncorrectDocblockException;
use Psalm\Internal\Analyzer\CommentAnalyzer;
use Psalm\Type;
use Psalm\Type\Union;

use function array_shift;
use function count;
use function implode;
use function in_array;
use function preg_last_error_msg;
use function preg_split;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Parses the value of a `@template` or `@template-covariant` tag (in its `@psalm-`/`@phpstan-` forms
 * too): `Name [of|as|super Bound] [= Default] [description]`. The bound and the default are returned
 * as written, description included: the scanner cuts them at the first top-level whitespace
 * (`CommentAnalyzer::splitDocLine`) when it parses them as types.
 *
 * @internal
 * @psalm-pure
 */
final class TemplateTagParser
{
    /**
     * @return array{name: string, modifier: ?string, bound: ?string, default: ?string}
     * @throws IncorrectDocblockException
     */
    public static function parse(string $line, string $tag_name): array
    {
        $line = CommentAnalyzer::sanitizeDocblockType($line);

        $equals_offset = self::findTopLevelEquals($line, 0);

        $head = $equals_offset === null ? $line : trim(substr($line, 0, $equals_offset));
        $default = $equals_offset === null ? null : trim(substr($line, $equals_offset + 1));

        $head_parts = preg_split('/\s+/', $head);

        if ($head_parts === false) {
            throw new IncorrectDocblockException('Invalid @' . $tag_name . ' tag: ' . preg_last_error_msg());
        }

        $name = array_shift($head_parts);

        if (!$name) {
            throw new IncorrectDocblockException('Empty @' . $tag_name . ' tag');
        }

        $modifier = null;
        $bound = null;

        if (count($head_parts) > 1 && in_array(strtolower($head_parts[0]), ['as', 'super', 'of'], true)) {
            $modifier = strtolower(array_shift($head_parts));
            $bound = implode(' ', $head_parts);
        } elseif ($default !== null
            && count($head_parts) === 1
            && in_array(strtolower($head_parts[0]), ['as', 'super', 'of'], true)
        ) {
            throw new IncorrectDocblockException('Template ' . $name . ' has an empty bound before its default');
        }

        if ($default !== null) {
            if ($default === '') {
                throw new IncorrectDocblockException('Template ' . $name . ' has an empty default');
            }

            if (self::findTopLevelEquals($default, 0) !== null) {
                throw new IncorrectDocblockException('Template ' . $name . ' has more than one default');
            }
        }

        return ['name' => $name, 'modifier' => $modifier, 'bound' => $bound, 'default' => $default];
    }

    /**
     * The first of the given templates a parsed default names, or null: the default is parsed with
     * the templates declared before it only, so the template itself and later ones (which it may not
     * name, which rules out cycles) are class names in it.
     *
     * @param list<string> $template_names
     */
    public static function findForwardReference(Union $default, array $template_names, Aliases $aliases): ?string
    {
        foreach ($template_names as $template_name) {
            if ($default->containsClassLike(strtolower(Type::getFQCLNFromString($template_name, $aliases)))) {
                return $template_name;
            }
        }

        return null;
    }

    /**
     * The offset of the first `=` outside brackets and quotes, or null when there is none
     */
    private static function findTopLevelEquals(string $line, int $start): ?int
    {
        $depth = 0;
        $quote_char = null;
        $escaped = false;

        for ($i = $start, $l = strlen($line); $i < $l; ++$i) {
            $char = $line[$i];

            if ($quote_char !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote_char) {
                    $quote_char = null;
                }

                continue;
            }

            if ($char === '"' || $char === '\'') {
                $quote_char = $char;
            } elseif ($char === '<' || $char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === '>' || $char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === '=' && $depth <= 0) {
                return $i;
            }
        }

        return null;
    }
}
