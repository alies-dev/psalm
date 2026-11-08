<?php

declare(strict_types=1);

namespace Psalm\Internal;

use ReflectionClass;
use ReflectionProperty;

/**
 * The properties that UnserializeMemoryUsageSuppressionTrait::__unserialize() can't write as `$this->$key`
 * from the class using the trait.
 *
 * @internal
 */
final class SerializedProperties
{
    /**
     * @var array<class-string, array<class-string, array<string, string|ReflectionProperty>>>
     */
    private static array $special_keys = [];

    /**
     * Key in `(array) $object` => the name of the property when $scope can write it, else the property
     *
     * The key of a private property holds its class, of a protected one `*`. A private or readonly property of a
     * class other than $scope can only be written from that class, or by reflection.
     *
     * @param class-string $class the class of the object
     * @param class-string $scope the class whose method writes the properties
     * @return array<string, string|ReflectionProperty>
     * @psalm-mutation-free
     * @psalm-suppress ImpureStaticProperty Used for caching
     */
    public static function getSpecialKeys(string $class, string $scope): array
    {
        return self::$special_keys[$class][$scope] ??= self::findSpecialKeys($class, $scope);
    }

    /**
     * @param class-string $class
     * @param class-string $scope
     * @return array<string, string|ReflectionProperty>
     * @psalm-mutation-free
     */
    private static function findSpecialKeys(string $class, string $scope): array
    {
        $special_keys = [];
        $seen_keys = [];

        // A class's own declaration of a property comes before its parents' ones
        for ($reflection = new ReflectionClass($class); $reflection; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                $declaring_class = $property->getDeclaringClass()->name;

                if ($property->isStatic() || $declaring_class !== $reflection->name) {
                    continue;
                }

                $name = $property->name;
                $key = match (true) {
                    $property->isPrivate() => "\0" . $declaring_class . "\0" . $name,
                    $property->isProtected() => "\0*\0" . $name,
                    default => $name,
                };

                if (isset($seen_keys[$key])) {
                    continue;
                }

                $seen_keys[$key] = true;

                if ($declaring_class !== $scope && ($property->isPrivate() || $property->isReadOnly())) {
                    $special_keys[$key] = $property;
                } elseif ($key !== $name) {
                    $special_keys[$key] = $name;
                }
            }
        }

        return $special_keys;
    }
}
