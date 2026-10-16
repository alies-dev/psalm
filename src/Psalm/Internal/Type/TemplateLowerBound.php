<?php

declare(strict_types=1);

namespace Psalm\Internal\Type;

use Psalm\Codebase;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Internal\TypeVisitor\TypeVariableResolver;
use Psalm\Type;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Helpers for templates declared with `@template T super Bound`.
 *
 * @internal
 */
final class TemplateLowerBound
{
    /**
     * Resolves the templates of the declaring class that the bound refers to
     * against the receiver. Returns null when the bound is not concrete
     * (e.g. when called on `$this`), in which case it cannot constrain anything.
     */
    public static function resolve(Union $bound, TemplateResult $template_result, Codebase $codebase): ?Union
    {
        $resolved = TemplateInferredTypeReplacer::replace($bound, $template_result, $codebase);

        (new TypeVariableResolver($codebase))->traverse($resolved);

        if ($resolved->hasMixed() || $resolved->hasTemplate()) {
            return null;
        }

        return $resolved;
    }

    /**
     * Turns a type inferred for the template into the least upper bound of it
     * and the template's declared lower bound, if there is one.
     */
    public static function widenInferred(
        Union $inferred,
        TTemplateParam $template,
        TemplateResult $template_result,
        Codebase $codebase,
    ): Union {
        $declared_bound = $template_result->declared_lower_bounds[$template->param_name][$template->defining_class]
            ?? null;

        $bound = $declared_bound ? self::resolve($declared_bound, $template_result, $codebase) : null;

        if (!$bound) {
            return $inferred;
        }

        if (UnionTypeComparator::isContainedBy($codebase, $inferred, $bound)) {
            return $bound;
        }

        if (UnionTypeComparator::isContainedBy($codebase, $bound, $inferred)) {
            return $inferred;
        }

        return Type::combineUnionTypes($inferred, $bound, $codebase);
    }

    /**
     * A template cannot be inferred as a supertype of the bound
     * when the inferred type can never overlap with it
     */
    public static function isDisjoint(Union $inferred, Union $bound, Codebase $codebase): bool
    {
        return !UnionTypeComparator::isContainedBy($codebase, $inferred, $bound)
            && !UnionTypeComparator::isContainedBy($codebase, $bound, $inferred)
            && !UnionTypeComparator::canBeContainedBy($codebase, $inferred, $bound, true, true);
    }
}
