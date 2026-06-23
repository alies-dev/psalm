# MissingOverrideAttribute

Emitted when the config flag `ensureOverrideAttribute` is set to `true` and a method on a child class or interface overrides a method on a parent, but no `Override` attribute is present.

```php
<?php

class A {
    function receive(): void
    {
    }
}

class B extends A {
    function receive(): void
    {
    }
}
```

On PHP 8.5 and above, the same applies to a property that overrides a parent (or implemented interface) property, including constructor promoted and static properties.

```php
<?php

class A {
    public int $value = 0;
}

class B extends A {
    public int $value = 1;
}
```

## Why this is bad

Having an `Override` attribute on overridden methods and properties makes intentions clear. Read the [PHP RFC](https://wiki.php.net/rfc/marking_overriden_methods) for more details.

## How to fix

Declare the `#[\Override]` attribute on all indicated methods and properties, or run `vendor/bin/psalter --issues=MissingOverrideAttribute` to let Psalm do it for you.  

Note that the `#[\Override]` attribute on a method is compatible with **all PHP versions**, even PHP 4. On a property it requires PHP 8.5, so Psalm reports (and fixes) missing attributes on properties only when analyzing PHP 8.5 or above.  

On PHP 8.0-8.2, require [symfony/polyfill-php83](https://packagist.org/packages/symfony/polyfill-php83) to polyfill the missing Override attribute.  