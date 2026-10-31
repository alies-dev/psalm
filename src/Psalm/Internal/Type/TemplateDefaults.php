<?php

declare(strict_types=1);

namespace Psalm\Internal\Type;

use Psalm\Codebase;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Issue\InvalidDocblock;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\Capabilities;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Union;

use function array_filter;
use function array_key_first;
use function array_keys;
use function array_values;
use function count;
use function in_array;
use function reset;
use function strtolower;

/**
 * Applies the defaults of type templates (`@template T = int`): what an omitted template argument of
 * a class, or a template of a function-like a call does not infer, stands for. A default may use the
 * templates declared before it, which it is resolved against.
 *
 * @internal
 */
final class TemplateDefaults
{
    /**
     * The classes whose defaults are being resolved, which a default using the class again
     * (`@template B = Other<A>` where `Other` defaults to this class) leaves short.
     *
     * @var array<lowercase-string, true>
     */
    private static array $resolving = [];

    /**
     * The type params of a use of the class, with the omitted trailing type arguments that have a
     * default filled in, up to the first one without. Without a codebase (during population), the
     * defaults are only resolved against the earlier templates, not expanded.
     *
     * @param list<Union> $type_params
     * @return ($type_params is non-empty-list ? non-empty-list<Union> : list<Union>)
     */
    public static function fill(?Codebase $codebase, ClassLikeStorage $storage, array $type_params): array
    {
        if (!$storage->template_types || count($type_params) >= count($storage->template_types)) {
            return $type_params;
        }

        [$type_args, $purity_args] = PurityArguments::split($type_params);

        $template_names = array_keys($storage->template_types);
        $given_count = count($type_args);

        if (!isset($template_names[$given_count])
            || !self::hasTypeDefault($storage, $template_names[$given_count])
        ) {
            return $type_params;
        }

        $class_lc = strtolower($storage->name);

        if (isset(self::$resolving[$class_lc])) {
            return $type_params;
        }

        self::$resolving[$class_lc] = true;

        try {
            $resolved = [];

            foreach ($template_names as $i => $template_name) {
                if ($i >= $given_count) {
                    if (!self::hasTypeDefault($storage, $template_name)) {
                        break;
                    }

                    $type_args[] = self::resolve($codebase, $storage, $template_name, $resolved);
                }

                $resolved[$template_name] = [$storage->name => $type_args[$i]];
            }
        } finally {
            unset(self::$resolving[$class_lc]);
        }

        return [...$type_args, ...$purity_args];
    }

    /**
     * The type params of a receiver of the class (an object a member is accessed on): those of
     * fill(), with the defaults also standing for the arguments that are unknown
     * (`from_template_default`: a use of the class without type arguments, see TypeExpander).
     *
     * @param list<Union> $type_params
     * @return ($type_params is non-empty-list ? non-empty-list<Union> : list<Union>)
     */
    public static function localize(Codebase $codebase, ClassLikeStorage $storage, array $type_params): array
    {
        $type_params = self::fill($codebase, $storage, $type_params);

        if (!self::hasTypeDefaults($storage)) {
            return $type_params;
        }

        [$type_args, $purity_args] = PurityArguments::split($type_params);

        $template_names = array_keys($storage->template_types ?? []);
        $resolved = [];
        $changed = false;

        foreach ($type_args as $i => $type_arg) {
            if (!isset($template_names[$i])) {
                break;
            }

            if ($type_arg->from_template_default && self::hasTypeDefault($storage, $template_names[$i])) {
                $type_args[$i] = $type_arg = self::resolve($codebase, $storage, $template_names[$i], $resolved);
                $changed = true;
            }

            $resolved[$template_names[$i]] = [$storage->name => $type_arg];
        }

        return $changed ? [...$type_args, ...$purity_args] : $type_params;
    }

    /**
     * The default of a type template of the class, if it has one, resolved against what the
     * templates before it stand for.
     *
     * @param array<string, Union> $earlier_params
     */
    public static function resolveDefault(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $template_name,
        array $earlier_params,
    ): ?Union {
        if (!self::hasTypeDefault($storage, $template_name)) {
            return null;
        }

        $resolved = [];

        foreach ($earlier_params as $name => $type) {
            $resolved[$name] = [$storage->name => $type];
        }

        return self::resolve($codebase, $storage, $template_name, $resolved);
    }

    /**
     * What each template of a use of the class without type arguments (`Foo`) stands for: its
     * default if it has one, else its bound.
     *
     * @return array<string, Union>
     */
    public static function bareParams(Codebase $codebase, ClassLikeStorage $storage): array
    {
        $params = [];
        $resolved = [];

        foreach ($storage->template_types ?? [] as $template_name => $type_map) {
            $params[$template_name] = self::hasTypeDefault($storage, $template_name)
                ? self::resolve($codebase, $storage, $template_name, $resolved)
                : $type_map[array_key_first($type_map)];

            $resolved[$template_name] = [$storage->name => $params[$template_name]];
        }

        return $params;
    }

    /**
     * The default of a template of a parent class that `@extends`/`@implements`/`@use` leaves out
     * (or that has no such tag), resolved against the parent's templates given before it.
     *
     * @param array<string, Union> $extended_params
     */
    public static function resolveExtended(
        ClassLikeStorage $parent_storage,
        string $template_name,
        array $extended_params,
    ): Union {
        $default = $parent_storage->template_defaults[$template_name];

        if (!self::hasTypeDefault($parent_storage, $template_name) || !$default->hasTemplate()) {
            return $default;
        }

        $lower_bounds = [];

        foreach ($extended_params as $name => $type) {
            $lower_bounds[$name] = [$parent_storage->name => $type];
        }

        return TemplateInferredTypeReplacer::replace($default, new TemplateResult([], $lower_bounds), null);
    }

    /**
     * Gives the templates with a default of the called function-like that no argument inferred
     * their default: a template that an argument bound keeps what it inferred (only that, without
     * the placeholders that arguments not binding it left), one that only a callable argument
     * constrained (`callable(T): void`) gets that constraint.
     */
    public static function applyToCall(
        Codebase $codebase,
        TemplateResult $template_result,
        FunctionLikeStorage $storage,
    ): void {
        foreach ($storage->template_defaults as $template_name => $default) {
            $defining_id = array_key_first($storage->template_types[$template_name] ?? []);

            if ($defining_id === null) {
                continue;
            }

            $lower_bounds = $template_result->lower_bounds[$template_name][$defining_id] ?? [];

            $inferred = array_values(array_filter(
                $lower_bounds,
                static fn(TemplateBound $bound): bool => !$bound->from_constraint_fallback,
            ));

            if ($inferred !== []) {
                $template_result->lower_bounds[$template_name][$defining_id] = $inferred;

                continue;
            }

            $type = isset($template_result->upper_bounds[$template_name][$defining_id])
                ? $template_result->upper_bounds[$template_name][$defining_id]->type
                : TemplateInferredTypeReplacer::replace($default, $template_result, $codebase);

            $template_result->lower_bounds[$template_name][$defining_id] = [new TemplateBound($type)];
        }
    }

    /**
     * Why the default of a template is invalid where it is declared, if it is: it may only use the
     * templates declared before it (and, for a method, those of its class), and cannot be a purity.
     *
     * @param list<string> $earlier_template_names the templates of $defining_id declared before
     * @psalm-mutation-free
     */
    public static function getDeclarationError(
        Union $default,
        string $template_name,
        array $earlier_template_names,
        string $defining_id,
    ): ?string {
        if (Capabilities::isPurityArgument($default)) {
            return 'The default of template ' . $template_name . ' cannot be a set of capabilities or a purity'
                . ' template, use @psalm-purity-template';
        }

        foreach ($default->getTemplateTypes() as $template_type) {
            if ($template_type->defining_class === $defining_id
                && !in_array($template_type->param_name, $earlier_template_names, true)
            ) {
                return 'The default of template ' . $template_name . ' uses template ' . $template_type->param_name
                    . ', which is not declared before it';
            }
        }

        return null;
    }

    /**
     * Reports the defaults of the templates of a classlike or function-like that name unknown
     * types or are not within the bounds of their templates (the templates they use stand for
     * their bounds), where they are declared.
     */
    public static function checkDeclaredDefaults(
        StatementsSource $source,
        ClassLikeStorage|FunctionLikeStorage $storage,
        ?string $self_class,
    ): void {
        if (!$storage->template_types || !$storage->template_defaults || !$storage->location) {
            return;
        }

        $codebase = $source->getCodebase();
        $suppressed_issues = $storage->suppressed_issues + $source->getSuppressedIssues();
        $template_bounds = [];

        foreach ($storage->template_types as $template_name => $type_map) {
            foreach ($type_map as $defining_id => $bound) {
                $template_bounds[$template_name] = [$defining_id => $bound];
            }
        }

        $template_result = new TemplateResult([], $template_bounds);

        foreach ($storage->template_defaults as $template_name => $default) {
            if ($storage instanceof ClassLikeStorage && in_array($template_name, $storage->purity_templates, true)) {
                continue;
            }

            if ($default->check($source, $storage->location, $suppressed_issues, [], false) === false) {
                continue;
            }

            $bound = reset($template_bounds[$template_name]);

            if ($bound === false || $bound->isMixed()) {
                continue;
            }

            $expanded_default = TypeExpander::expandUnion(
                $codebase,
                TemplateInferredTypeReplacer::replace($default, $template_result, $codebase),
                $self_class,
                $self_class,
                null,
            );
            $expanded_bound = TypeExpander::expandUnion(
                $codebase,
                TemplateInferredTypeReplacer::replace($bound, $template_result, $codebase),
                $self_class,
                $self_class,
                null,
            );

            if (!UnionTypeComparator::isContainedBy($codebase, $expanded_default, $expanded_bound)) {
                IssueBuffer::maybeAdd(
                    new InvalidDocblock(
                        'Default ' . $default->getId() . ' of template ' . $template_name . ' is not within its bound '
                            . $bound->getId(),
                        $storage->location,
                    ),
                    $suppressed_issues,
                );
            }
        }
    }

    /**
     * @psalm-mutation-free
     */
    private static function hasTypeDefault(ClassLikeStorage $storage, string $template_name): bool
    {
        return isset($storage->template_defaults[$template_name])
            && !in_array($template_name, $storage->purity_templates, true);
    }

    /**
     * @psalm-mutation-free
     */
    public static function hasTypeDefaults(ClassLikeStorage $storage): bool
    {
        foreach ($storage->template_defaults as $template_name => $_) {
            if (!in_array($template_name, $storage->purity_templates, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, Union>> $resolved the templates before this one
     */
    private static function resolve(
        ?Codebase $codebase,
        ClassLikeStorage $storage,
        string $template_name,
        array $resolved,
    ): Union {
        $default = $storage->template_defaults[$template_name];

        if ($resolved !== []) {
            $default = TemplateInferredTypeReplacer::replace($default, new TemplateResult([], $resolved), $codebase);
        }

        if ($codebase === null) {
            return $default;
        }

        return TypeExpander::expandUnion(
            $codebase,
            $default,
            $storage->name,
            $storage->name,
            $storage->parent_class,
        );
    }
}
