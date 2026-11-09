<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use AssertionError;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Type\StarProjection;
use Psalm\Internal\Type\TemplateDefaults;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TClassConstant;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

use function array_any;
use function array_keys;
use function array_merge;
use function array_search;
use function reset;

/**
 * @internal
 */
final class ClassTemplateParamCollector
{
    private static int $opened_stars = 0;

    /**
     * @param lowercase-string $method_name
     * @param ?string $star_scope the function-like (see {@see self::starScope()}) a `*` argument of the receiver is
     *                            opened in; without one, a `*` reads as the bound of its template
     * @return array<string, non-empty-array<string, Union>>|null
     */
    public static function collect(
        Codebase $codebase,
        ClassLikeStorage $class_storage,
        ClassLikeStorage $static_class_storage,
        ?string $method_name = null,
        ?Atomic $lhs_type_part = null,
        bool $self_call = false,
        ?string $star_scope = null,
    ): ?array {
        return self::collectForReceiver(
            $codebase,
            $class_storage,
            $static_class_storage,
            $method_name,
            $star_scope !== null && $lhs_type_part instanceof TGenericObject
                ? self::openStars($lhs_type_part, $codebase, $star_scope)
                : $lhs_type_part,
            $self_call,
        );
    }

    /**
     * @param lowercase-string $method_name
     * @return array<string, non-empty-array<string, Union>>|null
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    private static function collectForReceiver(
        Codebase $codebase,
        ClassLikeStorage $class_storage,
        ClassLikeStorage $static_class_storage,
        ?string $method_name = null,
        ?Atomic $lhs_type_part = null,
        bool $self_call = false,
    ): ?array {
        $non_trait_class_storage = $class_storage->is_trait
            ? $static_class_storage
            : $class_storage;

        $template_types = $class_storage->template_types;

        $candidate_class_storages = [$class_storage];

        if ($static_class_storage->template_extended_params
            && $method_name
            && !empty($non_trait_class_storage->overridden_method_ids[$method_name])
        ) {
            foreach ($non_trait_class_storage->overridden_method_ids[$method_name] as $overridden_method_id) {
                $overridden_storage = $codebase->methods->getStorage($overridden_method_id);

                if (!$overridden_storage->return_type) {
                    continue;
                }

                if ($overridden_storage->return_type->isNull()) {
                    continue;
                }

                $fq_overridden_class = $overridden_method_id->fq_class_name;

                $overridden_class_storage = $codebase->classlike_storage_provider->get($fq_overridden_class);

                $overridden_template_types = $overridden_class_storage->template_types;

                if (!$template_types) {
                    $template_types = $overridden_template_types;
                } elseif ($overridden_template_types) {
                    foreach ($overridden_template_types as $template_name => $template_map) {
                        if (isset($template_types[$template_name])) {
                            $template_types[$template_name] = array_merge(
                                $template_types[$template_name],
                                $template_map,
                            );
                        } else {
                            $template_types[$template_name] = $template_map;
                        }
                    }
                }

                $candidate_class_storages[] = $overridden_class_storage;
            }
        }

        if (!$template_types) {
            return null;
        }

        $class_template_params = [];
        $e = $static_class_storage->template_extended_params;

        if ($lhs_type_part instanceof TGenericObject) {
            // a `*` not opened in a scope (see collect()) reads as the bound of the template it stands for
            $lhs_type_part = StarProjection::readArguments($lhs_type_part, $codebase);

            if ($class_storage === $static_class_storage && $class_storage->template_types) {
                $lhs_type_params = TemplateDefaults::fillTypeArguments(
                    $lhs_type_part->type_params,
                    $class_storage,
                    $codebase,
                    $lhs_type_part->from_docblock,
                );
                $i = 0;

                foreach ($class_storage->template_types as $type_name => $_) {
                    if (isset($lhs_type_params[$i])) {
                        $class_template_params[$type_name][$class_storage->name] = $lhs_type_params[$i];
                    } elseif (isset($class_storage->purity_templates[$type_name])
                        && isset($class_storage->template_defaults[$type_name])
                    ) {
                        // a purity template left out (`Box<int>`) has its default
                        $class_template_params[$type_name][$class_storage->name]
                            = $class_storage->template_defaults[$type_name];
                    }

                    $i++;
                }
            }

            $template_result = null;
            if ($class_storage !== $static_class_storage && $static_class_storage->template_types) {
                $templates = self::collectForReceiver(
                    $codebase,
                    $static_class_storage,
                    $static_class_storage,
                    null,
                    $lhs_type_part,
                );
                if ($templates === null) {
                    throw new AssertionError("Could not collect templates!");
                }
                $template_result = new TemplateResult(
                    $static_class_storage->template_types,
                    $templates,
                );
            }
            foreach ($template_types as $type_name => $_) {
                // the templates of the classes documenting the method resolve against the receiver as well
                foreach ($candidate_class_storages as $candidate_class_storage) {
                    if ($candidate_class_storage !== $class_storage
                        && $candidate_class_storage !== $static_class_storage
                        && $template_result !== null
                        && isset($e[$candidate_class_storage->name][$type_name])
                        && !isset($class_template_params[$type_name][$candidate_class_storage->name])
                    ) {
                        $resolved_type = self::resolveTemplateParam(
                            $codebase,
                            $e[$candidate_class_storage->name][$type_name],
                            $static_class_storage,
                            $lhs_type_part,
                            $template_result,
                        );

                        if ($resolved_type !== null) {
                            $class_template_params[$type_name][$candidate_class_storage->name] = $resolved_type;
                        }
                    }
                }

                if (isset($class_template_params[$type_name][$class_storage->name])) {
                    continue;
                }

                if ($class_storage !== $static_class_storage
                    && isset($e[$class_storage->name][$type_name])
                ) {
                    $input_type_extends = $e[$class_storage->name][$type_name];

                    $output_type_extends = self::resolveTemplateParam(
                        $codebase,
                        $input_type_extends,
                        $static_class_storage,
                        $lhs_type_part,
                        $template_result,
                    );

                    $class_template_params[$type_name][$class_storage->name]
                        = $output_type_extends ?? Type::getMixed();
                } else {
                    $class_template_params[$type_name][$class_storage->name] = Type::getMixed();
                }
            }
        }

        foreach ($template_types as $type_name => $type_map) {
            foreach ($type_map as $type) {
                foreach ($candidate_class_storages as $candidate_class_storage) {
                    if ($candidate_class_storage !== $static_class_storage
                        && isset($e[$candidate_class_storage->name][$type_name])
                        && !isset($class_template_params[$type_name][$candidate_class_storage->name])
                    ) {
                        // the arguments the object was created with replace the templates of its own class
                        $resolved_type = null;

                        if ($lhs_type_part instanceof TGenericObject
                            && $static_class_storage->template_types !== null
                        ) {
                            $own_params = self::collectForReceiver(
                                $codebase,
                                $static_class_storage,
                                $static_class_storage,
                                null,
                                $lhs_type_part,
                            );

                            $resolved_type = self::resolveTemplateParam(
                                $codebase,
                                $e[$candidate_class_storage->name][$type_name],
                                $static_class_storage,
                                $lhs_type_part,
                                $own_params !== null
                                    ? new TemplateResult($static_class_storage->template_types, $own_params)
                                    : null,
                            );
                        }

                        $class_template_params[$type_name][$candidate_class_storage->name] = $resolved_type
                            ?? new Union(
                                self::expandType(
                                    $codebase,
                                    $e[$candidate_class_storage->name][$type_name],
                                    $e,
                                    $static_class_storage->name,
                                    $static_class_storage->template_types,
                                ),
                            );
                    }
                }

                if (!$self_call) {
                    if (!isset($class_template_params[$type_name])) {
                        $class_template_params[$type_name][$class_storage->name] = $type;
                    }
                }
            }
        }

        return $class_template_params;
    }

    /**
     * The defining class of the templates the `*` arguments of a receiver are opened into in $context: they belong
     * to the function-like being analysed, like its own templates, so they are not read as their bound there
     *
     * @psalm-capabilities read-props
     */
    public static function starScope(Context $context): string
    {
        return 'fn-' . ($context->calling_method_id ?? $context->calling_function_id);
    }

    /**
     * The receiver with each `*` argument opened into a fresh template: `Pair<int, *>` is a `Pair<int, X>` for an
     * unknown X within the bound, and no other X is known to equal it, so its members accept nothing for X
     */
    private static function openStars(TGenericObject $type, Codebase $codebase, string $star_scope): TGenericObject
    {
        if (!array_any($type->type_params, StarProjection::isStar(...))) {
            return $type;
        }

        $storage = $codebase->classlike_storage_provider->get($type->value);
        $template_types = $storage->template_types ?? [];
        $template_names = array_keys($template_types);
        $opened = [];
        $type_params = [];

        foreach ($type->type_params as $offset => $type_param) {
            $template_name = $template_names[$offset] ?? null;

            if (StarProjection::isStar($type_param)) {
                // a bound naming an earlier template (`@template B of A`) has the argument of that template
                $bound = $template_name === null
                    ? Type::getMixed()
                    : TemplateInferredTypeReplacer::replace(
                        reset($template_types[$template_name]),
                        new TemplateResult([], $opened),
                        $codebase,
                    );

                $type_param = new Union([new TTemplateParam(
                    '*' . ($template_name ?? $offset) . '#' . ++self::$opened_stars,
                    $bound,
                    $star_scope,
                )]);
            }

            if ($template_name !== null) {
                $opened[$template_name] = [$storage->name => $type_param];
            }

            $type_params[] = $type_param;
        }

        return $type->setTypeParams($type_params);
    }

    /**
     * @psalm-external-mutation-free
     */
    private static function resolveTemplateParam(
        Codebase $codebase,
        Union $input_type_extends,
        ClassLikeStorage $static_class_storage,
        TGenericObject $lhs_type_part,
        ?TemplateResult $template_result = null,
    ): ?Union {
        $output_type_extends = null;
        foreach ($input_type_extends->getAtomicTypes() as $type_extends_atomic) {
            if ($type_extends_atomic instanceof TTemplateParam) {
                if (isset(
                    $static_class_storage
                            ->template_types
                                [$type_extends_atomic->param_name]
                                [$type_extends_atomic->defining_class],
                )
                ) {
                    $mapped_offset = array_search(
                        $type_extends_atomic->param_name,
                        array_keys($static_class_storage->template_types),
                        true,
                    );

                    if ($mapped_offset !== false
                        && isset($lhs_type_part->type_params[$mapped_offset])
                    ) {
                        $output_type_extends = Type::combineUnionTypes(
                            $lhs_type_part->type_params[$mapped_offset],
                            $output_type_extends,
                        );
                    }
                } elseif (isset(
                    $static_class_storage
                        ->template_extended_params
                            [$type_extends_atomic->defining_class]
                            [$type_extends_atomic->param_name],
                )) {
                    $nested_output_type = self::resolveTemplateParam(
                        $codebase,
                        $static_class_storage
                        ->template_extended_params
                            [$type_extends_atomic->defining_class]
                            [$type_extends_atomic->param_name],
                        $static_class_storage,
                        $lhs_type_part,
                        $template_result,
                    );
                    if ($nested_output_type !== null) {
                        $output_type_extends = Type::combineUnionTypes(
                            $nested_output_type,
                            $output_type_extends,
                        );
                    }
                }
            } else {
                if ($template_result !== null) {
                    $type_extends_atomic = $type_extends_atomic->replaceTemplateTypesWithArgTypes(
                        $template_result,
                        $codebase,
                    );
                }
                $output_type_extends = Type::combineUnionTypes(
                    new Union([$type_extends_atomic]),
                    $output_type_extends,
                );
            }
        }
        return $output_type_extends;
    }

    /**
     * @param array<string, array<string, Union>> $e
     * @return non-empty-list<Atomic>
     */
    private static function expandType(
        Codebase $codebase,
        Union $input_type_extends,
        array $e,
        string $static_fq_class_name,
        ?array $static_template_types,
    ): array {
        $output_type_extends = [];

        foreach ($input_type_extends->getAtomicTypes() as $type_extends_atomic) {
            if ($type_extends_atomic instanceof TTemplateParam
                && ($static_fq_class_name !== $type_extends_atomic->defining_class
                    || !isset($static_template_types[$type_extends_atomic->param_name]))
                && isset($e[$type_extends_atomic->defining_class][$type_extends_atomic->param_name])
            ) {
                $output_type_extends = [...$output_type_extends, ...self::expandType(
                    $codebase,
                    $e[$type_extends_atomic->defining_class][$type_extends_atomic->param_name],
                    $e,
                    $static_fq_class_name,
                    $static_template_types,
                )];
            } elseif ($type_extends_atomic instanceof TClassConstant) {
                $expanded = TypeExpander::expandAtomic(
                    $codebase,
                    $type_extends_atomic,
                    $type_extends_atomic->fq_classlike_name,
                    $type_extends_atomic->fq_classlike_name,
                    null,
                    true,
                    true,
                );

                foreach ($expanded as $type) {
                    $output_type_extends[] = $type;
                }
            } else {
                $output_type_extends[] = $type_extends_atomic;
            }
        }

        return $output_type_extends;
    }
}
