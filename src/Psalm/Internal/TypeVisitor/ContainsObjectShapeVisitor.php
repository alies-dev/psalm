<?php

declare(strict_types=1);

namespace Psalm\Internal\TypeVisitor;

use Override;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;

/**
 * @internal
 */
final class ContainsObjectShapeVisitor extends TypeVisitor
{
    private bool $contains_object_shape = false;

    #[Override]
    protected function enterNode(TypeNode $type): ?int
    {
        if ($type instanceof TObjectWithProperties) {
            $this->contains_object_shape = true;
            return self::STOP_TRAVERSAL;
        }

        return null;
    }

    public function matches(): bool
    {
        return $this->contains_object_shape;
    }
}
