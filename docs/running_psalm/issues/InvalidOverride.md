# InvalidOverride

Emitted when an `Override` attribute was added to a method that does not override a method from a parent class or implemented interface.

```php
<?php

class A {
    function receive(): void
    {
    }
}

class B extends A {
    #[Override]
    function obtain(): void
    {
    }
}
```

On PHP 8.5 and above, the same applies to a property carrying the attribute that does not override a parent (or implemented interface) property.

```php
<?php

class A {
    public int $value = 0;
}

class B extends A {
    #[Override]
    public int $other = 1;
}
```

## Why this is bad

A fatal error will be thrown.
