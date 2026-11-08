<?php

declare(strict_types=1);

namespace Psalm\Tests\Storage;

use Psalm\Storage\UnserializeMemoryUsageSuppressionTrait;

abstract class SerializationFixtureParent
{
    use UnserializeMemoryUsageSuppressionTrait;

    public int $public = 0;

    protected string $protected = '';

    private ?string $parent_private = null;

    /** @psalm-suppress PropertyNotSetInConstructor Serialization must keep it uninitialized */
    public int $uninitialized;

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(public readonly string $parent_readonly)
    {
        $this->public = 1;
        $this->protected = 'protected';
        $this->parent_private = 'parent private';
    }
}
