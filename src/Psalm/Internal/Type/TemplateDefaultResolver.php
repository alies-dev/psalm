<?php

declare(strict_types=1);

namespace Psalm\Internal\Type;

use Psalm\Codebase;
use Psalm\Internal\TypeVisitor\TemplateTypeCollector;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type;
use Psalm\Type\Union;

use function array_filter;
use function array_key_first;
use function array_values;
use function count;
use function in_array;
use function reset;

/** @internal */
final class TemplateDefaultResolver
{
    /**
     * @param list<Union> $arguments
     * @return list<Union>
     * @psalm-mutation-free
     */
    public static function complete(
        ClassLikeStorage $storage,
        array $arguments = [],
        bool $bare = false,
        bool $recovery = false,
    ): array {
        if (!$storage->template_types || count($arguments) === count($storage->template_types)) {
            return $arguments;
        }
        if (!PurityArguments::fit($arguments, $storage)) {
            return $arguments;
        }
        [$types, $purities] = PurityArguments::split($arguments);
        if (!$bare && !$recovery && count($types) < $storage->getRequiredTemplateParamCount()) {
            return $arguments;
        }

        $result = new TemplateResult($storage->template_types, []);
        $type_offset = 0;
        $purity_offset = 0;
        foreach ($storage->template_types as $name => $bounds) {
            $argument = in_array($name, $storage->purity_templates, true)
                ? ($purities[$purity_offset++] ?? null)
                : ($types[$type_offset++] ?? null);
            if ($argument !== null) {
                foreach ($bounds as $owner => $_) {
                    $result->lower_bounds[$name][$owner] = [new TemplateBound($argument)];
                }
            }
        }
        foreach ($storage->purity_templates as $name) {
            foreach ($storage->template_types[$name] ?? [] as $owner => $bound) {
                if (!isset($result->lower_bounds[$name][$owner])) {
                    $result->lower_bounds[$name][$owner] = [
                        new TemplateBound(self::substitute($storage->template_defaults[$name] ?? $bound, $result)),
                    ];
                }
            }
        }

        $completed = [];
        foreach ($storage->template_types as $name => $bounds) {
            $owner = array_key_first($bounds);
            $argument = $result->lower_bounds[$name][$owner][0]->type ?? null;
            if ($argument === null) {
                $default = isset($storage->invalid_template_defaults[$name])
                    ? null
                    : ($storage->template_defaults[$name] ?? null);
                if ($default === null) {
                    $result->lower_bounds[$name][$owner] = [new TemplateBound(Type::getMixed())];
                }
                $argument = self::substitute($default ?? reset($bounds), $result);
                if ($bare && $default === null) {
                    $argument = PurityArguments::getOmittedArgument($argument);
                }
                $result->lower_bounds[$name][$owner] = [new TemplateBound($argument)];
            }
            $completed[] = $argument;
        }
        return $completed;
    }

    /** @psalm-mutation-free */
    public static function hasRecursiveBound(ClassLikeStorage $storage): bool
    {
        foreach ($storage->template_types ?? [] as $name => $bounds) {
            if (isset($storage->template_defaults[$name])) {
                continue;
            }
            $collector = new TemplateTypeCollector();
            $collector->traverse(reset($bounds));
            foreach ($collector->getTemplateTypes() as $reference) {
                if ($reference->param_name === $name && isset($bounds[$reference->defining_class])) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function register(TemplateResult $result, FunctionLikeStorage $storage): void
    {
        foreach ($storage->template_types ?? [] as $name => $bounds) {
            if (in_array($name, $storage->purity_templates, true)) {
                continue;
            }
            if (in_array($name, ['TFunctionArgCount', 'TPhpMajorVersion', 'TPhpVersionId'], true)) {
                continue;
            }
            foreach ($bounds as $owner => $bound) {
                $result->template_types[$name][$owner] = $bound;
                $result->type_template_declarations[$name][$owner] = true;
                if (isset($storage->template_defaults[$name]) && !isset($storage->invalid_template_defaults[$name])) {
                    $result->template_defaults[$name][$owner] = $storage->template_defaults[$name];
                }
            }
        }
    }

    public static function finalize(TemplateResult $result, Codebase $codebase): void
    {
        foreach ($result->type_template_declarations as $name => $owners) {
            foreach ($owners as $owner => $_) {
                $actual = array_values(array_filter(
                    $result->lower_bounds[$name][$owner] ?? [],
                    static fn(TemplateBound $bound): bool
                        => !$bound->from_constraint_fallback && !$bound->from_template_default,
                ));
                if ($actual) {
                    $result->lower_bounds[$name][$owner] = $actual;
                    continue;
                }
                $upper = $result->upper_bounds[$name][$owner] ?? null;
                if ($upper !== null) {
                    $result->lower_bounds[$name][$owner] = [new TemplateBound($upper->type)];
                    continue;
                }
                $expression = $result->template_defaults[$name][$owner]
                    ?? $result->template_types[$name][$owner];
                $fallback = new TemplateBound(self::substitute($expression, $result, $codebase));
                $fallback->from_template_default = true;
                $result->lower_bounds[$name][$owner] = [$fallback];
            }
        }
    }

    public static function substitute(Union $type, TemplateResult $result, ?Codebase $codebase = null): Union
    {
        return $result->lower_bounds ? TemplateInferredTypeReplacer::replace($type, $result, $codebase) : $type;
    }
}
