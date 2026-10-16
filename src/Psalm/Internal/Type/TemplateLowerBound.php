<?php

declare(strict_types=1);

namespace Psalm\Internal\Type;

use Psalm\Codebase;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Internal\TypeVisitor\TypeVariableResolver;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Helpers for templates declared with `@template T super Bound`.
 *
 * @internal
 */
final class TemplateLowerBound
{
    /**
     * Registers the declared bounds of the called function-like on the template result,
     * expanding `self`/`static`/`parent` in the class context of the call.
     */
    public static function declare(
        TemplateResult $template_result,
        FunctionLikeStorage $storage,
        Codebase $codebase,
        ?string $self_class,
        ?string $static_class,
        ?string $parent_class,
        bool $final,
    ): void {
        foreach ($storage->template_lower_bounds as $template_name => $bound_map) {
            foreach ($bound_map as $template_id => $bound) {
                $template_result->declared_lower_bounds[$template_name][$template_id] ??= TypeExpander::expandUnion(
                    $codebase,
                    $bound,
                    $self_class,
                    $static_class,
                    $parent_class,
                    true,
                    false,
                    $final,
                );
            }
        }
    }

    /**
     * Resolves the templates of the declaring class that the bound refers to
     * against the receiver. Returns null when the bound cannot constrain anything:
     * it is unknown, or still names a template of the declaring class (a `$this` receiver).
     * Templates of other scopes stay in the bound.
     */
    public static function resolve(Union $bound, TemplateResult $template_result, Codebase $codebase): ?Union
    {
        $plain_result = clone $template_result;
        $plain_result->declared_lower_bounds = [];

        $resolved = TemplateInferredTypeReplacer::replace($bound, $plain_result, $codebase);

        (new TypeVariableResolver($codebase))->traverse($resolved);

        if ($resolved->hasMixed()) {
            return null;
        }

        foreach ($bound->getTemplateTypes() as $own_template) {
            foreach ($resolved->getTemplateTypes() as $remaining_template) {
                if ($remaining_template->param_name === $own_template->param_name
                    && $remaining_template->defining_class === $own_template->defining_class
                ) {
                    return null;
                }
            }
        }

        return $resolved;
    }

    /**
     * Replaces the types inferred for `super` templates with the least upper bound
     * of the inferred type and the declared lower bound
     *
     * @return array<string, array<string, non-empty-list<TemplateBound>>>
     */
    public static function widenInferredBounds(TemplateResult $template_result, Codebase $codebase): array
    {
        // bounds may refer to earlier templates, which must already be widened
        $working_result = clone $template_result;
        $working_result->declared_lower_bounds = [];

        foreach ($template_result->declared_lower_bounds as $template_name => $bound_map) {
            foreach ($bound_map as $template_id => $declared_bound) {
                if (!isset($working_result->lower_bounds[$template_name][$template_id])) {
                    continue;
                }

                $bound = self::resolve($declared_bound, $working_result, $codebase);

                if (!$bound) {
                    continue;
                }

                $inferred = TemplateStandinTypeReplacer::getMostSpecificTypeFromBounds(
                    $working_result->lower_bounds[$template_name][$template_id],
                    $codebase,
                );

                if (UnionTypeComparator::isContainedBy($codebase, $inferred, $bound)) {
                    $widened = $bound;
                } elseif (UnionTypeComparator::isContainedBy($codebase, $bound, $inferred)) {
                    $widened = $inferred;
                } else {
                    $widened = Type::combineUnionTypes($inferred, $bound, $codebase);
                }

                $working_result->lower_bounds[$template_name][$template_id] = [new TemplateBound($widened)];
            }
        }

        return $working_result->lower_bounds;
    }
}
