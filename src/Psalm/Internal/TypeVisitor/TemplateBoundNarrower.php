<?php

declare(strict_types=1);

namespace Psalm\Internal\TypeVisitor;

use Override;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

/**
 * Replaces the declared bound of one template param, leaving the rest of the type structure untouched
 *
 * @internal
 */
final class TemplateBoundNarrower extends MutableTypeVisitor
{
    public function __construct(
        private readonly string $param_name,
        private readonly string $defining_class,
        private readonly Union $bound,
    ) {
    }

    #[Override]
    protected function enterNode(TypeNode &$type): ?int
    {
        if ($type instanceof TTemplateParam
            && $type->param_name === $this->param_name
            && $type->defining_class === $this->defining_class
        ) {
            $type = $type->replaceAs($this->bound);

            return self::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }
}
