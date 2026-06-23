<?php

declare(strict_types=1);

namespace Psalm\Tests\FileManipulation;

use Override;

final class MissingOverrideAttributeManipulationTest extends FileManipulationTestCase
{
    protected bool $ensure_override_attribute = true;

    #[Override]
    public function providerValidCodeParse(): array
    {
        return [
            'addToOverridingProperty' => [
                'input' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public int $a = 1;
                    }',
                'output' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        #[\Override]
                        public int $a = 1;
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'addToOverridingStaticProperty' => [
                'input' => '<?php
                    class P {
                        public static int $a = 0;
                    }
                    class C extends P {
                        public static int $a = 1;
                    }',
                'output' => '<?php
                    class P {
                        public static int $a = 0;
                    }
                    class C extends P {
                        #[\Override]
                        public static int $a = 1;
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'addToOverridingPromotedPropertyInline' => [
                'input' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public function __construct(public int $a = 1) {}
                    }',
                'output' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public function __construct(#[\Override] public int $a = 1) {}
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'addToOverridingPromotedPropertyMultiline' => [
                'input' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public function __construct(
                            public int $a = 1,
                        ) {}
                    }',
                'output' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public function __construct(
                            #[\Override] public int $a = 1,
                        ) {}
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'addToInterfacePropertyImplementation' => [
                'input' => '<?php
                    interface I {
                        public int $a { get; }
                    }
                    class C implements I {
                        public int $a = 1;
                    }',
                'output' => '<?php
                    interface I {
                        public int $a { get; }
                    }
                    class C implements I {
                        #[\Override]
                        public int $a = 1;
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'addAlongsideExistingAttribute' => [
                'input' => '<?php
                    #[\Attribute]
                    class Foo {}
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        #[Foo]
                        public int $a = 1;
                    }',
                'output' => '<?php
                    #[\Attribute]
                    class Foo {}
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        #[\Override]
                        #[Foo]
                        public int $a = 1;
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'doesNotTouchPropertyThatAlreadyHasAttribute' => [
                'input' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        #[\Override]
                        public int $a = 1;
                    }',
                'output' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        #[\Override]
                        public int $a = 1;
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'doesNotTouchNonOverridingProperty' => [
                'input' => '<?php
                    class C {
                        public int $a = 1;
                    }',
                'output' => '<?php
                    class C {
                        public int $a = 1;
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'doesNotAddBeforePhp85' => [
                'input' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public int $a = 1;
                    }',
                'output' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public int $a = 1;
                    }',
                'php_version' => '8.4',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'addToPromotedPropertyAlongsideExistingAttribute' => [
                'input' => '<?php
                    #[\Attribute]
                    class Foo {}
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public function __construct(#[Foo] public int $a = 1) {}
                    }',
                'output' => '<?php
                    #[\Attribute]
                    class Foo {}
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public function __construct(#[\Override] #[Foo] public int $a = 1) {}
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
            'doesNotFixMultiPropertyStatement' => [
                'input' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public int $a = 1, $b = 2;
                    }',
                'output' => '<?php
                    class P {
                        public int $a = 0;
                    }
                    class C extends P {
                        public int $a = 1, $b = 2;
                    }',
                'php_version' => '8.5',
                'issues_to_fix' => ['MissingOverrideAttribute'],
                'safe_types' => true,
            ],
        ];
    }
}
