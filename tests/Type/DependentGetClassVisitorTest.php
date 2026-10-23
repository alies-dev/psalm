<?php

declare(strict_types=1);

namespace Psalm\Tests\Type;

use Override;
use Psalm\Tests\TestCase;
use Psalm\Type\Atomic\TDependentGetClass;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

final class DependentGetClassVisitorTest extends TestCase
{
    public function testTypeVisitorReachesNodesInsideAsType(): void
    {
        $type = new Union([new TDependentGetClass('$x', new Union([new TNamedObject('Foo')]))]);

        $visitor = new class extends TypeVisitor {
            /** @var list<string> */
            public array $visited = [];

            /**
             * @psalm-external-mutation-free
             */
            #[Override]
            protected function enterNode(TypeNode $type): ?int
            {
                if ($type instanceof TNamedObject) {
                    $this->visited[] = $type->value;
                }

                return null;
            }
        };

        $visitor->traverse($type);

        self::assertSame(['Foo'], $visitor->visited);
    }

    public function testMutableTypeVisitorRewritesNodesInsideAsType(): void
    {
        $original = new TDependentGetClass('$x', new Union([new TNamedObject('Foo')]));
        $type = new Union([$original]);

        $visitor = new class extends MutableTypeVisitor {
            /**
             * @psalm-capabilities read-props|write-this-props|write-refs
             */
            #[Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if ($type instanceof TNamedObject && $type->value === 'Foo') {
                    $type = new TNamedObject('Bar');
                }

                return null;
            }
        };

        $visitor->traverse($type);

        $atomics = $type->getAtomicTypes();
        self::assertCount(1, $atomics);

        $rewritten = $atomics['get-class-of<$x, Bar>'] ?? null;
        self::assertInstanceOf(TDependentGetClass::class, $rewritten);
        self::assertNotSame($original, $rewritten);
        self::assertSame('$x', $rewritten->typeof);
        self::assertSame('Bar', $rewritten->as_type->getId());

        // the original node is left untouched
        self::assertSame('Foo', $original->as_type->getId());
    }
}
