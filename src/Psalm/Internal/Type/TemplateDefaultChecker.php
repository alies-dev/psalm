<?php

declare(strict_types=1);

namespace Psalm\Internal\Type;

use Override;
use Psalm\Codebase;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Issue\InvalidDocblock;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TTypeAlias;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

use function array_key_first;
use function array_keys;
use function count;
use function reset;

/** @internal */
final class TemplateDefaultChecker
{
    /**
     * @param array<ClassLikeStorage> $classes
     * @param array<FileStorage> $files
     */
    public static function check(Codebase $codebase, array $classes, array $files): void
    {
        foreach ($classes as $class) {
            self::checkStorage($codebase, $class, $class);
            foreach ($class->methods as $method) {
                self::checkStorage($codebase, $method, $class);
            }
        }
        foreach ($files as $file) {
            foreach ($file->functions as $function) {
                self::checkStorage($codebase, $function, null);
            }
        }
    }

    private static function checkStorage(
        Codebase $codebase,
        ClassLikeStorage|FunctionLikeStorage $storage,
        ?ClassLikeStorage $class,
    ): void {
        foreach ($storage->template_default_locations as $name => $location) {
            if (isset($storage->invalid_template_defaults[$name])) {
                continue;
            }
            $default = $storage->template_defaults[$name];
            $bound = reset($storage->template_types[$name]);
            if (self::hasCycle($codebase, $default, [array_key_first($storage->template_types[$name]) . ':' . $name => true])) {
                $message = 'Cyclic default for template ' . $name;
            } else {
                $default = TypeExpander::expandUnion(
                    $codebase,
                    $default,
                    $class?->name,
                    $class?->name,
                    $class?->parent_class,
                );
                $storage->template_defaults[$name] = $default;
                $invalid_type = self::hasCycle(
                    $codebase,
                    $default,
                    [array_key_first($storage->template_types[$name]) . ':' . $name => true],
                ) ? 'Cyclic default for template ' . $name : self::invalidType($codebase, $default);
                $dependent_bound = $bound->isSingle()
                    && $bound->getSingleAtomic() instanceof TTemplateParam
                    && !$default->isNever()
                    && $default->getId() !== $bound->getId();
                $message = $invalid_type ?? (!$dependent_bound
                    && UnionTypeComparator::isContainedBy($codebase, $default, $bound)
                    ? null
                    : 'Default ' . $default->getId() . ' for template ' . $name
                        . ' is not contained by bound ' . $bound->getId());
            }
            if ($message !== null) {
                $storage->invalid_template_defaults[$name] = true;
                $storage->docblock_issues[] = new InvalidDocblock($message, $location);
            }
        }
    }

    private static function invalidType(Codebase $codebase, Union $type): ?string
    {
        $visitor = new class($codebase) extends TypeVisitor {
            public ?string $error = null;

            public function __construct(private readonly Codebase $codebase)
            {
            }

            #[Override]
            protected function enterNode(TypeNode $node): ?int
            {
                if ($node instanceof TTemplateParam) {
                    return self::DONT_TRAVERSE_CHILDREN;
                }
                if (!$node instanceof TNamedObject) {
                    return null;
                }
                if (!$this->codebase->classlike_storage_provider->has($node->value)) {
                    $this->error = 'Unknown class ' . $node->value . ' in template default';
                    return self::STOP_TRAVERSAL;
                }
                $storage = $this->codebase->classlike_storage_provider->get($node->value);
                if ($storage->is_trait) {
                    $this->error = 'Trait ' . $node->value . ' cannot be a template default type';
                    return self::STOP_TRAVERSAL;
                }
                if ($node instanceof TGenericObject) {
                    [$arguments] = PurityArguments::split($node->type_params);
                    if (count($arguments) < $storage->getRequiredTemplateParamCount()
                        || !PurityArguments::fit($node->type_params, $storage)
                    ) {
                        $this->error = 'Invalid generic arguments for ' . $node->value . ' in template default';
                        return self::STOP_TRAVERSAL;
                    }
                    $parameters = TemplateDefaultResolver::complete($storage, $node->type_params);
                    $bindings = [];
                    $names = array_keys($storage->template_types ?? []);
                    foreach ($parameters as $offset => $argument) {
                        foreach ($storage->template_types[$names[$offset]] as $owner => $_) {
                            $bindings[$names[$offset]][$owner] = $argument;
                        }
                    }
                    $result = new TemplateResult([], $bindings);
                    foreach ($parameters as $offset => $argument) {
                        foreach ($storage->template_types[$names[$offset]] as $bound) {
                            $expected = TemplateDefaultResolver::substitute($bound, $result, $this->codebase);
                            if (!UnionTypeComparator::isContainedBy($this->codebase, $argument, $expected)) {
                                $this->error = 'Generic argument ' . $argument->getId() . ' is outside bound '
                                    . $expected->getId() . ' in template default';
                                return self::STOP_TRAVERSAL;
                            }
                        }
                    }
                }
                return null;
            }
        };
        $visitor->traverse($type);
        return $visitor->error;
    }

    /** @param array<string, true> $active */
    private static function hasCycle(Codebase $codebase, Union $type, array $active): bool
    {
        $visitor = new class extends TypeVisitor {
            /** @var list<TNamedObject> */
            public array $objects = [];

            /** @var list<TTypeAlias> */
            public array $aliases = [];

            #[Override]
            protected function enterNode(TypeNode $node): ?int
            {
                if ($node instanceof TTemplateParam) {
                    return self::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof TTypeAlias) {
                    $this->aliases[] = $node;
                }
                if ($node instanceof TNamedObject) {
                    $this->objects[] = $node;
                }
                return null;
            }
        };
        $visitor->traverse($type);
        foreach ($visitor->aliases as $alias) {
            $owner = $alias->declaring_fq_classlike_name;
            $key = 'alias:' . $owner . ':' . $alias->alias_name;
            if (isset($active[$key])) {
                return true;
            }
            if (!$codebase->classlike_storage_provider->has($owner)) {
                continue;
            }
            $replacement = $codebase->classlike_storage_provider->get($owner)->type_aliases[$alias->alias_name] ?? null;
            if ($replacement && $replacement->replacement_atomic_types
                && self::hasCycle($codebase, new Union($replacement->replacement_atomic_types), $active + [$key => true])
            ) {
                return true;
            }
        }
        foreach ($visitor->objects as $object) {
            if (!$codebase->classlike_storage_provider->has($object->value)) {
                continue;
            }
            $storage = $codebase->classlike_storage_provider->get($object->value);
            $supplied = $object instanceof TGenericObject ? count($object->type_params) : 0;
            $offset = 0;
            foreach ($storage->template_types ?? [] as $name => $_) {
                if ($offset++ < $supplied || !isset($storage->template_defaults[$name])) {
                    continue;
                }
                $key = $storage->name . ':' . $name;
                if (isset($active[$key])
                    || self::hasCycle($codebase, $storage->template_defaults[$name], $active + [$key => true])
                ) {
                    return true;
                }
            }
        }
        return false;
    }
}
