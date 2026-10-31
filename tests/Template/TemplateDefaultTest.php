<?php

declare(strict_types=1);

namespace Psalm\Tests\Template;

use Override;
use Psalm\Tests\TestCase;
use Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

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
            'extendsOmittedDefault' => [
                'code' => '<?php
                    /**
                     * @template TModel of object
                     * @template TCount of int|null = null
                     */
                    abstract class Factory {
                        /** @return TCount */
                        public function count() { return null; }
                        /** @return (TCount is null|0|1 ? TModel : list<TModel>) */
                        public function create() { return null; }
                    }
                    final class User {}
                    /** @extends Factory<User> */
                    final class UserFactory extends Factory {}
                    /** @extends Factory<User, 5> */
                    final class UserFactory5 extends Factory {}

                    $count = (new UserFactory())->count();
                    $created = (new UserFactory())->create();
                    $created5 = (new UserFactory5())->create();',
                'assertions' => [
                    '$count===' => 'null',
                    '$created===' => 'User',
                    '$created5===' => 'list<User>',
                ],
            ],
            'extendsWithoutAnnotation' => [
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
            'docblockShortGeneric' => [
                'code' => '<?php
                    /**
                     * @template TA
                     * @template TB of string = "pivot"
                     */
                    abstract class Rel {
                        /** @return TB */
                        public function acc(): string { throw new Exception(); }
                    }
                    /** @return Rel<int> */
                    function getRel(): Rel { throw new Exception(); }

                    $acc = getRel()->acc();',
                'assertions' => [
                    '$acc===' => "'pivot'",
                ],
            ],
            'bareReceiver' => [
                'code' => '<?php
                    /**
                     * @template TM of object = stdClass
                     * @template TN = int
                     */
                    abstract class Res {
                        /** @return TM */
                        public function m(): object { throw new Exception(); }
                        /** @return TN */
                        public function n() { throw new Exception(); }
                    }
                    /** @return list{stdClass, int} */
                    function f(Res $bare): array {
                        return [$bare->m(), $bare->n()];
                    }',
            ],
            'dependentDefault' => [
                'code' => '<?php
                    /**
                     * @template T
                     * @template U = list<T>
                     */
                    abstract class Pair {
                        /** @return U */
                        public function u() { throw new Exception(); }
                    }
                    /** @extends Pair<int> */
                    final class IntPair extends Pair {}

                    /** @return Pair<string> */
                    function getPair(): Pair { throw new Exception(); }

                    $x = (new IntPair())->u();
                    $y = getPair()->u();',
                'assertions' => [
                    '$x===' => 'list<int>',
                    '$y===' => 'list<string>',
                ],
            ],
            'newUsesDefault' => [
                'code' => '<?php
                    /** @template T = int */
                    final class Box {
                        /** @param T $v */
                        public function set($v): void {}
                    }
                    /** @template T of object = stdClass */
                    final class OBox {
                        /** @param T|null $t */
                        public function __construct(?object $t = null) {}
                    }

                    $b = new Box();
                    $o = new OBox();',
                'assertions' => [
                    '$b===' => 'Box<int>',
                    '$o===' => 'OBox<stdClass>',
                ],
            ],
            'propertyAndForeachOfBareReceiver' => [
                'code' => '<?php
                    /**
                     * @template TK of array-key = int
                     * @template TV = string
                     * @implements IteratorAggregate<TK, TV>
                     */
                    final class Coll implements IteratorAggregate {
                        /** @var TV|null */
                        public $last = null;
                        /** @return Iterator<TK, TV> */
                        public function getIterator(): Iterator { throw new Exception(); }
                    }
                    /** @return list{string|null, int, string} */
                    function f(Coll $bare): array {
                        foreach ($bare as $k => $v) {
                            return [$bare->last, $k, $v];
                        }
                        throw new Exception();
                    }',
            ],
            'functionLikeDefaults' => [
                'code' => '<?php
                    /**
                     * @template T
                     */
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
                    /**
                     * @template TD = never
                     * @param (callable(): TD)|null $d
                     * @return string|TD
                     */
                    function get(?callable $d = null) { return ""; }
                    /**
                     * @template T = int
                     * @return list<T>
                     */
                    function emptyListD(): array { return []; }
                    /**
                     * @template T = string
                     * @param callable(T): void $cb
                     * @return T
                     */
                    function cbonly(callable $cb) { throw new Exception(); }

                    /** @return Promise<int> */
                    function getPromise(): Promise { throw new Exception(); }

                    $nn = getPromise()->then(null, null);
                    $omitted = getPromise()->then();
                    $a = get();
                    $b = get(null);
                    $f = emptyListD();
                    $x = cbonly(function (int $_i): void {});',
                'assertions' => [
                    '$nn===' => 'Promise<int>',
                    '$omitted===' => 'Promise<int>',
                    '$a===' => 'string',
                    '$b===' => 'string',
                    '$f===' => 'list<int>',
                    '$x===' => 'int',
                ],
            ],
            'methodDefaultThroughOverrideAndTrait' => [
                'code' => '<?php
                    /** @template T */
                    abstract class Base {
                        /**
                         * @template R = T
                         * @param (callable(T): R)|null $cb
                         * @return list<R>
                         */
                        public function map(?callable $cb = null): array { return []; }
                    }
                    /** @extends Base<int> */
                    final class Child extends Base {
                        public function map(?callable $cb = null): array { return []; }
                    }
                    /** @template T */
                    trait MapsT {
                        /**
                         * @template R = T
                         * @param (callable(T): R)|null $cb
                         * @return list<R>
                         */
                        public function tmap(?callable $cb = null): array { return []; }
                    }
                    final class UsesT {
                        /** @use MapsT<string> */
                        use MapsT;
                    }

                    $x = (new Child())->map();
                    $y = (new UsesT())->tmap();',
                'assertions' => [
                    '$x===' => 'list<int>',
                    '$y===' => 'list<string>',
                ],
            ],
            'defaultSyntax' => [
                'code' => '<?php
                    /**
                     * @template T of string = "a=b"
                     * @template U = array{a: int, b?: string}
                     * @template W = array<string, int> Some text = not a default
                     * @template X=int
                     * @psalm-template Y = int
                     * @template Y = string
                     */
                    abstract class Parse {
                        /** @return list{T, U, W, X, Y} */
                        public function all(): array { throw new Exception(); }
                    }
                    /** @template T of array-key The key, e.g. a = b */
                    abstract class Desc {}

                    function getParse(): Parse { throw new Exception(); }

                    $all = getParse()->all();',
                'assertions' => [
                    '$all===' => "list{'a=b', array{a: int, b?: string}, array<string, int>, int, int}",
                ],
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
            'shortFormIsExact' => [
                'code' => '<?php
                    /**
                     * @template TA
                     * @template TB of string = "pivot"
                     */
                    final class Rel {}
                    /** @param Rel<int> $r */
                    function takes(Rel $r): void {}
                    /** @param Rel<int, "other"> $r */
                    function f(Rel $r): void { takes($r); }',
                'error_message' => 'InvalidArgument',
            ],
            'defaultOutOfBound' => [
                'code' => '<?php
                    /** @template T of int = string */
                    abstract class Bad {}',
                'error_message' => 'InvalidDocblock',
            ],
            'forwardReference' => [
                'code' => '<?php
                    /**
                     * @template T = U
                     * @template U = int
                     */
                    abstract class Fwd {}',
                'error_message' => 'InvalidDocblock',
            ],
            'requiredAfterOptional' => [
                'code' => '<?php
                    /**
                     * @template A = int
                     * @template B
                     */
                    abstract class ReqAfterOpt {}',
                'error_message' => 'InvalidDocblock',
            ],
            'undefinedClassInDefault' => [
                'code' => '<?php
                    /** @template T = NoSuchClass */
                    abstract class UndefDefault {}',
                'error_message' => 'UndefinedDocblockClass',
            ],
            'functionDefaultOutOfBound' => [
                'code' => '<?php
                    /**
                     * @template T of string = int
                     * @return T
                     */
                    function outOfBound() { throw new Exception(); }',
                'error_message' => 'InvalidDocblock',
            ],
            'missingWithoutDefault' => [
                'code' => '<?php
                    /**
                     * @template TA
                     * @template TB = int
                     */
                    abstract class Two {}
                    final class NoArgs extends Two {}',
                'error_message' => 'MissingTemplateParam',
            ],
        ];
    }
}
