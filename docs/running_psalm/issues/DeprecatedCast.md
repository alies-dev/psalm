# DeprecatedCast

Emitted when a non canonical scalar cast spelling is used and the analysis target is PHP 8.5 or later:

```php
<?php

function toBool(mixed $value): bool {
    return (boolean) $value;
}
```

## Why this is bad

PHP 8.5 deprecates the longer cast aliases `(boolean)`, `(integer)`, `(double)` and `(binary)` in favour of the canonical `(bool)`, `(int)`, `(float)` and `(string)`. The deprecated spellings emit an `E_DEPRECATED` notice at runtime and may be removed in a future version.

This issue is only reported when the configured `phpVersion` (or the autodetected target version) is 8.5 or above, since the spellings are still valid on earlier versions.

## How to fix

Use the canonical cast:

```php
<?php

function toBool(mixed $value): bool {
    return (bool) $value;
}
```
