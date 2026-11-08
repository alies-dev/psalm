<?php

declare(strict_types=1);

namespace Psalm\Tests\Storage;

final class SerializationFixture extends SerializationFixtureParent
{
    private array $private = [];

    /**
     * @psalm-capabilities read-props
     */
    public function __construct(public readonly int $readonly)
    {
        parent::__construct('parent readonly');
        $this->private = ['private'];
    }
}
