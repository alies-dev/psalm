<?php

declare(strict_types=1);

namespace Psalm\Tests\Internal;

use Override;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\Truthy;

/**
 * An assertion class Psalm does not know, like one a plugin could add.
 *
 * @psalm-immutable
 */
final class UnknownAssertion extends Assertion
{
    /** @psalm-pure */
    #[Override]
    public function getNegation(): Assertion
    {
        return new Truthy();
    }

    /** @psalm-pure */
    #[Override]
    public function isNegationOf(Assertion $assertion): bool
    {
        return $assertion instanceof Falsy || $assertion instanceof Truthy;
    }

    /** @psalm-pure */
    #[Override]
    public function __toString(): string
    {
        return 'unknown';
    }
}
