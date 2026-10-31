<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor\Reflector;

use Psalm\Aliases;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Exception\TypeParseTreeException;
use Psalm\Internal\Type\TypeAlias;
use Psalm\Internal\Type\TypeParser;
use Psalm\Internal\Type\TypeTokenizer;
use Psalm\Internal\TypeVisitor\TemplateTypeCollector;
use Psalm\Internal\TypeVisitor\TypeScanner;
use Psalm\Issue\InvalidDocblock;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Union;

use function array_key_first;
use function in_array;

/** @internal */
final class TemplateDefaultScanner
{
    /**
     * @param array<string, string> $defaults
     * @param array<string, non-empty-array<string, Union>> $templates
     * @param array<string, TypeAlias> $aliases
     */
    public static function scan(
        ClassLikeStorage|FunctionLikeStorage $storage,
        array $defaults,
        array $templates,
        Aliases $namespace,
        array $aliases,
        CodeLocation $location,
        Codebase $codebase,
        FileStorage $file,
    ): void {
        if (!$defaults) {
            return;
        }
        $optional = false;
        $earlier = [];
        foreach ($storage->template_types ?? [] as $name => $bounds) {
            if (in_array($name, $storage->purity_templates, true)) {
                continue;
            }
            if (!isset($defaults[$name])) {
                if ($optional) {
                    $storage->docblock_issues[] = new InvalidDocblock(
                        'Required template ' . $name . ' cannot follow a template with a default',
                        $location,
                    );
                }
                $earlier[$name] = true;
                continue;
            }
            $optional = true;
            $storage->template_default_locations[$name] = $location;
            try {
                $default = TypeParser::parseTokens(
                    TypeTokenizer::getFullyQualifiedTokens($defaults[$name], $namespace, $templates, $aliases),
                    null,
                    $templates,
                    $aliases,
                );
                $collector = new TemplateTypeCollector();
                $collector->traverse($default);
                foreach ($collector->getTemplateTypes() as $reference) {
                    if ($reference->defining_class === array_key_first($bounds)
                        && !isset($earlier[$reference->param_name])
                        && !in_array($reference->param_name, $storage->purity_templates, true)
                    ) {
                        throw new TypeParseTreeException('Default for ' . $name . ' references itself or a later template');
                    }
                }
                $scanner = new TypeScanner($codebase->scanner, $file, $templates);
                $scanner->traverse($default);
                $storage->template_defaults[$name] = $default;
            } catch (TypeParseTreeException $e) {
                $storage->docblock_issues[] = new InvalidDocblock($e->getMessage(), $location);
                $storage->template_defaults[$name] = $bounds[array_key_first($bounds)];
                $storage->invalid_template_defaults[$name] = true;
            }
            $earlier[$name] = true;
        }
    }
}
