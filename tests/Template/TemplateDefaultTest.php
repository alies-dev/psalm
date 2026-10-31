<?php

declare(strict_types=1);

namespace Psalm\Tests\Template;

use Override;
use Psalm\Tests\TestCase;
use Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

use const DIRECTORY_SEPARATOR;

final class TemplateDefaultTest extends TestCase
{
    use InvalidCodeAnalysisTestTrait;
    use ValidCodeAnalysisTestTrait;

    /**
     * @psalm-pure
     */
    #[Override]
    public static function providerValidCodeParse(): iterable
    {
        return [
            'classDefaultOmittedInExtends' => [
                'code' => '<?php
                    /**
                     * @template TModel of object
                     * @template TCount of int|null = null
                     */
                    abstract class Factory {
                        /** @return TCount */
                        public function count(): mixed { return null; }
                        /** @return (TCount is null|0|1 ? TModel : list<TModel>) */
                        public function create(): mixed { return null; }
                    }
                    final class User {}
                    /** @extends Factory<User> */
                    final class UserFactory extends Factory {}

                    $factory = new UserFactory();
                    $count = $factory->count();
                    $created = $factory->create();',
                'assertions' => [
                    '$count===' => 'null',
                    '$created===' => 'User',
                ],
                'ignored_issues' => [],
                'php_version' => '8.0',
            ],
            'classDefaultNoExtendsAtAll' => [
                'code' => '<?php
                    /** @template TM of object = stdClass */
                    abstract class Res {
                        /** @return TM */
                        public function m(): object { throw new Exception(); }
                    }
                    final class MyRes extends Res {}

                    $m = (new MyRes())->m();',
                'assertions' => [
                    '$m===' => 'stdClass',
                ],
            ],
            'docblockGenericTrailingOmittedAndBareReceiver' => [
                'code' => '<?php
                    /**
                     * @template TA
                     * @template TB of string = \'pivot\'
                     */
                    abstract class Rel {
                        /** @return TB */
                        public function acc(): string { throw new Exception(); }
                    }

                    /** @return Rel<int> */
                    function partial(): Rel { throw new Exception(); }
                    function bare(): Rel { throw new Exception(); }

                    $acc = partial()->acc();
                    $bare_acc = bare()->acc();',
                'assertions' => [
                    '$acc===' => "'pivot'",
                    '$bare_acc===' => "'pivot'",
                ],
            ],
            'defaultReferencesEarlierClassTemplate' => [
                'code' => '<?php
                    /**
                     * @template T
                     * @template U = list<T>
                     */
                    abstract class Pair {
                        /** @return U */
                        public function u(): mixed { throw new Exception(); }
                    }
                    /** @extends Pair<int> */
                    final class IntPair extends Pair {}

                    /** @return Pair<string> */
                    function stringPair(): Pair { throw new Exception(); }

                    $x = (new IntPair())->u();
                    $y = stringPair()->u();',
                'assertions' => [
                    '$x===' => 'list<int>',
                    '$y===' => 'list<string>',
                ],
                'ignored_issues' => [],
                'php_version' => '8.0',
            ],
            'methodDefaultReferencesClassTemplate' => [
                'code' => '<?php
                    /** @template T */
                    interface Promise {
                        /**
                         * @template TResult1 = T
                         * @template TResult2 = never
                         * @param (callable(T): TResult1)|null $a
                         * @param (callable(mixed): TResult2)|null $b
                         * @return Promise<TResult1|TResult2>
                         */
                        public function then(?callable $a = null, ?callable $b = null): Promise;
                    }

                    /** @return Promise<int> */
                    function intPromise(): Promise { throw new Exception(); }

                    $nn = intPromise()->then(null, null);
                    $omitted = intPromise()->then();
                    $second = intPromise()->then(null, fn(mixed $e): string => "x");',
                'assertions' => [
                    '$nn===' => 'Promise<int>',
                    '$omitted===' => 'Promise<int>',
                    '$second===' => "Promise<'x'|int>",
                ],
                'ignored_issues' => [],
                'php_version' => '8.0',
            ],
            'functionDefaultForOmittedAndNullArgument' => [
                'code' => '<?php
                    /**
                     * @template R = int
                     * @param (callable(): R)|null $cb
                     * @return string|R
                     */
                    function get(?callable $cb = null) { return ""; }

                    $a = get();
                    $b = get(null);
                    $c = get(fn(): float => 1.5);',
                'assertions' => [
                    '$a===' => 'int|string',
                    '$b===' => 'int|string',
                    '$c===' => 'float(1.5)|string',
                ],
            ],
            'functionDefaultDoesNotOverrideUpperBoundInference' => [
                'code' => '<?php
                    /**
                     * @template T = string
                     * @param callable(T): void $cb
                     * @return T
                     */
                    function cbonly(callable $cb): mixed { throw new Exception(); }

                    $x = cbonly(function (int $i): void {});',
                'assertions' => [
                    '$x===' => 'int',
                ],
                'ignored_issues' => [],
                'php_version' => '8.0',
            ],
            'phpDefaultValueBindsBeforeDocblockDefault' => [
                'code' => '<?php
                    /**
                     * @template T of bool = true
                     * @param T $v
                     * @return T
                     */
                    function flag(bool $v = false): bool { return $v; }

                    $e = flag();',
                'assertions' => [
                    '$e===' => 'false',
                ],
            ],
            'newWithUnboundDefaultedTemplate' => [
                'code' => '<?php
                    /** @template T = int */
                    final class Box {}
                    /** @template T of object = stdClass */
                    final class OBox {
                        /** @param T|null $t */
                        public function __construct(?object $t = null) {}
                    }

                    $b = new Box();
                    $o = new OBox();
                    $n = new OBox(null);',
                'assertions' => [
                    '$b===' => 'Box<int>',
                    '$o===' => 'OBox<stdClass>',
                    '$n===' => 'OBox<stdClass>',
                ],
            ],
            'defaultWithCovariantTemplateAndPurityTemplate' => [
                'code' => '<?php
                    /**
                     * @template-covariant TValue = mixed
                     * @template T of string = \'a\'
                     * @psalm-purity-template P(pure) <= io
                     */
                    abstract class Mix {
                        /** @return T */
                        public function t(): string { throw new Exception(); }
                    }

                    /** @return Mix<int> */
                    function intMix(): Mix { throw new Exception(); }
                    /** @return Mix[pure] */
                    function pureMix(): Mix { throw new Exception(); }
                    function bareMix(): Mix { throw new Exception(); }
                    /** @param Mix<mixed> $m */
                    function takesMixed(Mix $m): void {}

                    takesMixed(intMix());
                    takesMixed(bareMix());
                    $t = pureMix()->t();',
                'assertions' => [
                    '$t===' => "'a'",
                ],
            ],
            'equalsInsideQuotesAndBracesAndDescriptionAfterDefault' => [
                'code' => '<?php
                    /**
                     * @template T of string = \'a=b\'
                     * @template S = array{k: \'x=y\'}
                     * @template-covariant V of int|null = 5 the count
                     * @phpstan-template W=list<T>
                     */
                    abstract class Def {
                        /** @return T */
                        public function t(): string { throw new Exception(); }
                        /** @return S */
                        public function s(): array { throw new Exception(); }
                        /** @return V */
                        public function v(): mixed { throw new Exception(); }
                        /** @return W */
                        public function w(): mixed { throw new Exception(); }
                    }

                    function def(): Def { throw new Exception(); }

                    $t = def()->t();
                    $s = def()->s();
                    $v = def()->v();
                    $w = def()->w();',
                'assertions' => [
                    '$t===' => "'a=b'",
                    '$s===' => "array{k: 'x=y'}",
                    '$v===' => '5',
                    '$w===' => "list<'a=b'>",
                ],
                'ignored_issues' => [],
                'php_version' => '8.0',
            ],
        ];
    }

    /**
     * @psalm-pure
     */
    #[Override]
    public static function providerInvalidCodeParse(): iterable
    {
        return [
            'requiredTemplateStillMissing' => [
                'code' => '<?php
                    /**
                     * @template T
                     * @template U = int
                     */
                    abstract class Foo {}
                    final class Sub extends Foo {}',
                'error_message' => 'MissingTemplateParam - src' . DIRECTORY_SEPARATOR . 'somefile.php:7:33 - Sub has'
                    . ' missing template params when extending Foo, expecting at least 1',
            ],
            'partiallyGivenArgumentsAreInvariantWithTheDefault' => [
                'code' => '<?php
                    /**
                     * @template TA
                     * @template TB of string = \'pivot\'
                     */
                    final class Rel {}

                    /** @param Rel<int> $r */
                    function takes(Rel $r): void {}

                    /** @param Rel<int, \'other\'> $r */
                    function f(Rel $r): void { takes($r); }',
                'error_message' => 'InvalidArgument - src' . DIRECTORY_SEPARATOR . 'somefile.php:12:54 - Argument 1'
                    . ' of takes expects Rel<int, \'pivot\'>, but Rel<int, \'other\'> provided',
            ],
            'defaultOutsideBound' => [
                'code' => '<?php
                    /** @template T of int = string */
                    abstract class Bad {}',
                'error_message' => 'InvalidTemplateParam - src' . DIRECTORY_SEPARATOR . 'somefile.php:3:21 - Default'
                    . ' type string of template T of Bad is not within its bound int',
            ],
            'methodDefaultOutsideBound' => [
                'code' => '<?php
                    /**
                     * @template T of int = \'a\'
                     * @return T
                     */
                    function bad(): int { throw new Exception(); }',
                'error_message' => 'InvalidTemplateParam',
            ],
            'defaultUndefinedClass' => [
                'code' => '<?php
                    /** @template T = Nope */
                    abstract class Bad {}',
                'error_message' => 'UndefinedDocblockClass',
            ],
            'defaultNamesLaterTemplate' => [
                'code' => '<?php
                    /**
                     * @template T = U
                     * @template U = int
                     */
                    abstract class Fwd {}',
                'error_message' => 'InvalidDocblock - src' . DIRECTORY_SEPARATOR . 'somefile.php:6:36 - The default of'
                    . ' template T may only name templates declared before it, U given, in docblock for Fwd',
            ],
            'defaultNamesItself' => [
                'code' => '<?php
                    /** @template T = T */
                    abstract class SelfRef {}',
                'error_message' => 'InvalidDocblock',
            ],
            'emptyDefault' => [
                'code' => '<?php
                    /** @template T of int = */
                    abstract class Empty_ {}',
                'error_message' => 'InvalidDocblock - src' . DIRECTORY_SEPARATOR . 'somefile.php:3:36 - Template T has an'
                    . ' empty default',
            ],
            'purityTemplateWithEquals' => [
                'code' => '<?php
                    /** @psalm-purity-template P = pure */
                    abstract class PurEq {}',
                'error_message' => 'InvalidDocblock',
            ],
        ];
    }
}
