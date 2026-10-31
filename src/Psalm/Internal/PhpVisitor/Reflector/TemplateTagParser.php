<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor\Reflector;

use Psalm\Exception\DocblockParseException;
use Psalm\Exception\IncorrectDocblockException;
use Psalm\Internal\Analyzer\CommentAnalyzer;

use function array_pop;
use function ltrim;
use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function str_starts_with;
use function strtolower;
use function trim;

/** @internal */
final class TemplateTagParser
{
    /**
     * @psalm-pure
     * @return array{string, ?string, ?string, ?string}
     */
    public static function parse(string $line): array
    {
        $line = trim(CommentAnalyzer::sanitizeDocblockType($line));
        if (!preg_match('/^([^\s=]+)(.*)$/s', $line, $matches)) {
            throw new IncorrectDocblockException('Empty @template tag');
        }

        $name = $matches[1];
        $rest = ltrim($matches[2]);
        $modifier = null;
        $bound = null;
        if (preg_match('/^(of|as|super)\s+(.+)$/is', $rest, $matches)) {
            $modifier = strtolower($matches[1]);
            $rest = $matches[2];
            [$bound, $rest] = self::typePrefix($rest);
        }

        $default = null;
        if (str_starts_with($rest, '=')) {
            [$default, $rest] = self::typePrefix(ltrim(substr($rest, 1)));
            if ($default === '' || str_starts_with($rest, '=')) {
                throw new DocblockParseException('Invalid default for template ' . $name);
            }
        }

        return [$name, $modifier, $bound, $default];
    }

    /**
     * @psalm-pure
     * @return array{string, string}
     */
    private static function typePrefix(string $text): array
    {
        $prefix = CommentAnalyzer::splitDocLine($text)[0];
        $brackets = [];
        $quote = null;
        $escaped = false;
        $separator = null;
        for ($i = 0, $length = strlen($prefix); $i < $length; $i++) {
            $char = $prefix[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (strpos('<([{', $char) !== false) {
                $brackets[] = $char;
            } elseif (($closing = strpos('>)]}', $char)) !== false) {
                if (!$brackets || $brackets[count($brackets) - 1] !== '<([{'[$closing]) {
                    throw new DocblockParseException('Unbalanced template type');
                }
                array_pop($brackets);
            } elseif ($char === '=' && !$brackets) {
                $separator = $i;
                break;
            }
        }
        if ($quote !== null || $brackets) {
            throw new DocblockParseException('Unbalanced template type');
        }

        if ($separator !== null) {
            return [trim(substr($prefix, 0, $separator)), ltrim(substr($text, $separator))];
        }
        $rest = ltrim(substr($text, strlen($prefix)));
        return [trim($prefix), str_starts_with($rest, '=') ? $rest : ''];
    }
}
