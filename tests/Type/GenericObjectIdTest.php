<?php

declare(strict_types=1);

namespace Psalm\Tests\Type;

use Psalm\Tests\TestCase;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

final class GenericObjectIdTest extends TestCase
{
    public function testStaticMarkerIsOnlyPartOfTheExactId(): void
    {
        $type = new TGenericObject('Foo', [Type::getInt()], is_static: true);

        self::assertSame('Foo<int>&static', $type->getId());
        self::assertSame('Foo<int>', $type->getId(false));
    }

    public function testGenericObjectMatchesNamedObjectWhenNotExact(): void
    {
        self::assertSame('Foo&static', (new TNamedObject('Foo', true))->getId());
        self::assertSame('Foo', (new TNamedObject('Foo', true))->getId(false));
    }

    public function testStaticMarkerIsDroppedInsideNestedTypesWhenNotExact(): void
    {
        $static_generic = new TGenericObject('Foo', [Type::getInt()], is_static: true);

        $union = new Union([$static_generic, new TNull()]);

        self::assertSame('Foo<int>&static|null', $union->getId());
        self::assertSame('Foo<int>|null', $union->getId(false));

        $array = new Union([new TArray([Type::getArrayKey(), new Union([$static_generic])])]);

        self::assertSame('array<array-key, Foo<int>&static>', $array->getId());
        self::assertSame('array<array-key, Foo<int>>', $array->getId(false));
    }

    public function testStaticMarkerIsKeptAfterIntersectionTypesWhenExact(): void
    {
        $type = new TGenericObject(
            'Foo',
            [Type::getInt()],
            is_static: true,
            extra_types: ['Bar' => new TNamedObject('Bar')],
        );

        self::assertSame('Foo<int>&Bar&static', $type->getId());
        self::assertSame('Foo<int>&Bar', $type->getId(false));
    }
}
