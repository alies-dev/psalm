<?php

declare(strict_types=1);

namespace Psalm\Internal\Type;

use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\Storage\Capabilities;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type;
use Psalm\Type\Union;

use function array_key_first;
use function count;
use function reset;
use function str_starts_with;

/**
 * What the templates a use of a class or a call of a function-like leaves unbound stand for: the
 * template's default (`@template T = int`) when it has one, else its bound (a class use) or
 * whatever the call site does today (a call).
 *
 * @internal
 */
final class TemplateDefaults
{
    /**
     * What a type argument left out of a use of the class stands for: the default of the template,
     * or its bound when it has none. A purity template stands for its upper bound, as before.
     *
     * @psalm-mutation-free
     */
    public static function omittedArgument(ClassLikeStorage $storage, string $template_name): Union
    {
        $bound = reset($storage->template_types[$template_name]);

        if (Capabilities::isPurityType($bound)) {
            return PurityArguments::getOmittedArgument($bound);
        }

        return $storage->template_defaults[$template_name] ?? PurityArguments::getOmittedArgument($bound);
    }

    /**
     * The type params of a use of the class with the trailing type templates left out filled with
     * their defaults (`Foo<int>` for `@template T @template U = list<T>` becomes `Foo<int, list<int>>`),
     * up to the first template without one. The purity arguments are left as given.
     *
     * @param list<Union> $type_params
     * @return list<Union>
     */
    public static function completeTypeParams(ClassLikeStorage $storage, array $type_params, ?Codebase $codebase): array
    {
        [$type_args, $purity_args] = PurityArguments::split($type_params);

        if (count($type_args) >= PurityArguments::countTypeTemplates($storage)) {
            return $type_params;
        }

        return [...self::fill($storage, $type_args, $codebase, false, false), ...$purity_args];
    }

    /**
     * The type arguments given followed by what every type template left out stands for: its default,
     * or its bound for one without. What `Foo[pure]` stands for before its purity arguments.
     *
     * @param list<Union> $type_args
     * @return list<Union>
     */
    public static function fillTypeArguments(ClassLikeStorage $storage, array $type_args, ?Codebase $codebase): array
    {
        return self::fill($storage, $type_args, $codebase, true, false);
    }

    /**
     * What a use of the class without type arguments stands for: every template's default or bound.
     *
     * @return list<Union>
     */
    public static function allArguments(ClassLikeStorage $storage, ?Codebase $codebase): array
    {
        return self::fill($storage, [], $codebase, true, true);
    }

    /**
     * The type arguments given followed by what the templates left out stand for, a default naming an
     * earlier template resolved against the argument given or filled for it.
     *
     * @param list<Union> $type_args
     * @param bool $with_bounds whether to fill a template without a default with its bound, or to stop
     * @param bool $with_purity_templates whether to fill the purity templates too, or to stop at them
     * @return list<Union>
     */
    private static function fill(
        ClassLikeStorage $storage,
        array $type_args,
        ?Codebase $codebase,
        bool $with_bounds,
        bool $with_purity_templates,
    ): array {
        $template_result = new TemplateResult([], []);
        $i = 0;

        foreach ($storage->template_types ?? [] as $template_name => $type_map) {
            if ($i >= count($type_args)) {
                $has_default = isset($storage->template_defaults[$template_name]);

                if (Capabilities::isPurityType(reset($type_map))) {
                    if (!$with_purity_templates) {
                        break;
                    }

                    $type_arg = PurityArguments::getOmittedArgument(reset($type_map));
                } elseif (!$has_default && !$with_bounds) {
                    break;
                } else {
                    $type_arg = self::omittedArgument($storage, $template_name);

                    if ($has_default && $type_arg->getTemplateTypes() !== []) {
                        $type_arg = TemplateInferredTypeReplacer::replace($type_arg, $template_result, $codebase);
                    }
                }

                $type_args[] = $type_arg;
            }

            $template_result->lower_bounds[$template_name][$storage->name] = [new TemplateBound($type_args[$i])];
            $i++;
        }

        return $type_args;
    }

    /**
     * Whether a call bound nothing to a template of the function-like: it has no lower bound, or only
     * the placeholders standing for arguments that bound nothing (`from_constraint_fallback`) and no
     * upper bound from the parameter of a callable argument (which is an inference).
     *
     * @psalm-mutation-free
     */
    public static function isUnresolved(TemplateResult $template_result, string $template_name, string $defining_class): bool
    {
        if (!isset($template_result->lower_bounds[$template_name][$defining_class])) {
            return true;
        }

        if (isset($template_result->upper_bounds[$template_name][$defining_class])) {
            return false;
        }

        foreach ($template_result->lower_bounds[$template_name][$defining_class] as $lower_bound) {
            if (!$lower_bound->from_constraint_fallback) {
                return false;
            }
        }

        return true;
    }

    /**
     * Binds the templates with a default that the call bound nothing to, in declaration order, to
     * their default, a default naming a template resolved against what the call bound it to. A
     * template of the function-like a default names that is itself unresolved stands for nothing
     * (`never`), as an unresolved template without a default does.
     */
    public static function bindUnresolvedFunctionTemplates(
        FunctionLikeStorage $storage,
        TemplateResult $template_result,
        Codebase $codebase,
    ): void {
        foreach ($storage->template_defaults as $template_name => $default) {
            if (!isset($storage->template_types[$template_name])) {
                continue;
            }

            $defining_class = array_key_first($storage->template_types[$template_name]);

            if (!self::isUnresolved($template_result, $template_name, $defining_class)) {
                continue;
            }

            $value = TemplateInferredTypeReplacer::replace($default, $template_result, $codebase);

            $unresolved = new TemplateResult([], []);

            foreach ($value->getTemplateTypes() as $template_type) {
                if (str_starts_with($template_type->defining_class, 'fn-')) {
                    $unresolved->lower_bounds[$template_type->param_name][$template_type->defining_class]
                        = [new TemplateBound(Type::getNever())];
                }
            }

            if ($unresolved->lower_bounds !== []) {
                $value = TemplateInferredTypeReplacer::replace($value, $unresolved, $codebase);
            }

            $template_result->lower_bounds[$template_name][$defining_class] = [new TemplateBound($value)];
        }
    }

    /**
     * Binds the templates with a default of the method a call resolves to that the arguments bound
     * nothing to: those of the method declaring it, or, for an override without a docblock, of the
     * method documenting it, whose return type the call gets.
     */
    public static function bindUnresolvedMethodTemplates(
        Codebase $codebase,
        MethodIdentifier $method_id,
        TemplateResult $template_result,
    ): void {
        $declaring_method_id = $codebase->methods->getDeclaringMethodId($method_id) ?? $method_id;

        if (!$codebase->methods->hasStorage($declaring_method_id)) {
            return;
        }

        $storage = $codebase->methods->getStorage($declaring_method_id);

        if (!$storage->template_types && $codebase->classlike_storage_provider->has($declaring_method_id->fq_class_name)) {
            $class_storage = $codebase->classlike_storage_provider->get($declaring_method_id->fq_class_name);
            $documenting_method_id = $class_storage->documenting_method_ids[$declaring_method_id->method_name] ?? null;

            if ($documenting_method_id !== null && $codebase->methods->hasStorage($documenting_method_id)) {
                $storage = $codebase->methods->getStorage($documenting_method_id);
            }
        }

        if ($storage->template_defaults !== []) {
            self::bindUnresolvedFunctionTemplates($storage, $template_result, $codebase);
        }
    }
}
