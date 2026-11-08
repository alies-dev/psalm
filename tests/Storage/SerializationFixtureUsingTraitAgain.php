<?php

declare(strict_types=1);

namespace Psalm\Tests\Storage;

use Psalm\Storage\UnserializeMemoryUsageSuppressionTrait;

final class SerializationFixtureUsingTraitAgain extends SerializationFixtureParent
{
    use UnserializeMemoryUsageSuppressionTrait;

    /** The parent's private property of the same name is another property */
    private ?string $parent_private = null;

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(public readonly int $readonly)
    {
        parent::__construct('parent readonly');
        $this->parent_private = 'own private';
    }
}
