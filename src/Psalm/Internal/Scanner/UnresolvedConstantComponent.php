<?php

declare(strict_types=1);

namespace Psalm\Internal\Scanner;

use Psalm\Storage\ImmutableNonCloneableTrait;
use Psalm\Storage\UnserializeMemoryUsageSuppressionTrait;

/**
 * @internal
 * @psalm-immutable
 */
abstract class UnresolvedConstantComponent
{
    use ImmutableNonCloneableTrait;
    use UnserializeMemoryUsageSuppressionTrait;
}
