<?php

declare(strict_types=1);

namespace Psalm;

/**
 * Controls JIT acceleration behavior during Psalm execution.
 *
 * @internal
 */
enum JitMode: string
{
    /** Enable JIT when available, fall back gracefully if not */
    case Auto = 'auto';

    /** Always enable JIT, exit if unavailable */
    case On = 'on';

    /** Never enable JIT */
    case Off = 'off';

    public static function fromConfig(string $value): self
    {
        return match (strtolower($value)) {
            'true', '1', 'on' => self::On,
            'false', '0', 'off' => self::Off,
            default => self::Auto,
        };
    }
}