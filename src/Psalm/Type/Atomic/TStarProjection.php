<?php

declare(strict_types=1);

namespace Psalm\Type\Atomic;

/**
 * Denotes the `*` star projection in a generic argument (`Foo<*>`): any type within the template's bound.
 * It prints and behaves as `mixed` until TypeExpander replaces it with the bound of the template at its position.
 *
 * @psalm-immutable
 */
final class TStarProjection extends TMixed
{
}
