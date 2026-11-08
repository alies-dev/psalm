<?php

declare(strict_types=1);

namespace Psalm\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Psalm\Internal\Algebra;
use Psalm\Internal\Clause;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Any;
use Psalm\Storage\Assertion\ArrayKeyDoesNotExist;
use Psalm\Storage\Assertion\ArrayKeyExists;
use Psalm\Storage\Assertion\DoesNotHaveAtLeastCount;
use Psalm\Storage\Assertion\DoesNotHaveExactCount;
use Psalm\Storage\Assertion\DoesNotHaveMethod;
use Psalm\Storage\Assertion\Empty_;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\HasArrayKey;
use Psalm\Storage\Assertion\HasAtLeastCount;
use Psalm\Storage\Assertion\HasExactCount;
use Psalm\Storage\Assertion\HasMethod;
use Psalm\Storage\Assertion\InArray;
use Psalm\Storage\Assertion\IsAClass;
use Psalm\Storage\Assertion\IsClassEqual;
use Psalm\Storage\Assertion\IsClassNotEqual;
use Psalm\Storage\Assertion\IsCountable;
use Psalm\Storage\Assertion\IsEqualIsset;
use Psalm\Storage\Assertion\IsGreaterThan;
use Psalm\Storage\Assertion\IsGreaterThanOrEqualTo;
use Psalm\Storage\Assertion\IsIdentical;
use Psalm\Storage\Assertion\IsIsset;
use Psalm\Storage\Assertion\IsLessThan;
use Psalm\Storage\Assertion\IsLessThanOrEqualTo;
use Psalm\Storage\Assertion\IsLooselyEqual;
use Psalm\Storage\Assertion\IsNotAClass;
use Psalm\Storage\Assertion\IsNotCountable;
use Psalm\Storage\Assertion\IsNotIdentical;
use Psalm\Storage\Assertion\IsNotIsset;
use Psalm\Storage\Assertion\IsNotLooselyEqual;
use Psalm\Storage\Assertion\IsNotType;
use Psalm\Storage\Assertion\IsType;
use Psalm\Storage\Assertion\NonEmpty;
use Psalm\Storage\Assertion\NonEmptyCountable;
use Psalm\Storage\Assertion\NotInArray;
use Psalm\Storage\Assertion\NotNonEmptyCountable;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Tests\Internal\ReferenceAlgebra;
use Psalm\Tests\Internal\UnknownAssertion;
use Psalm\Type;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TString;
use Throwable;

use function array_keys;
use function array_map;
use function count;
use function mt_rand;
use function mt_srand;
use function reset;
use function spl_object_id;
use function str_repeat;

/**
 * Algebra::simplifyCNF must return exactly what the reference implementation returns:
 * the same Clause objects (or equal new ones), in the same order, with the same flags and key order.
 */
final class AlgebraEquivalenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     * @psalm-pure
     */
    public static function providerShapes(): iterable
    {
        yield 'small formulas' => ['small', 1, 30_000];
        yield 'medium formulas' => ['medium', 2, 6_000];
        yield 'switch chains' => ['switch', 3, 100];
        yield 'wide formulas around the 256 clause limit' => ['wide', 4, 40];
        yield 'unknown references' => ['unknown', 5, 40];
    }

    #[DataProvider('providerShapes')]
    public function testSimplifyCnfMatchesReference(string $shape, int $seed, int $iterations): void
    {
        mt_srand($seed);
        $pool = self::makeAssertionPool();

        for ($i = 0; $i < $iterations; $i++) {
            $clauses = self::makeFormula($shape, $pool);

            $expected = self::describe(self::simplify(true, $clauses), $clauses);
            $actual = self::describe(self::simplify(false, $clauses), $clauses);

            $this->assertSame($expected, $actual, "$shape formula #$i");
        }
    }

    /**
     * @return list<Assertion>
     */
    private static function makeAssertionPool(): array
    {
        $long_prefix = str_repeat('x', 85);
        $named_object = new TNamedObject('Foo');
        $other_named_object = new TNamedObject('Foo');
        $int_array = Type::getInt();

        $pool = [
            new Truthy(),
            new Falsy(),
            new IsIsset(),
            new IsNotIsset(),
            new IsEqualIsset(),
            new Any(),
            new NonEmpty(),
            new Empty_(),
            new IsType(new TInt()),
            new IsNotType(new TInt()),
            new IsType(new TString()),
            new IsNotType(new TString()),
            new IsType(new TLiteralInt(0)),
            new IsNotType(new TLiteralInt(0)),
            new IsType(new TLiteralInt(5)),
            new IsNotType(new TLiteralInt(5)),
            new IsIdentical(new TLiteralString('a')),
            new IsNotIdentical(new TLiteralString('a')),
            new IsIdentical(new TLiteralString('b')),
            new IsNotIdentical(new TLiteralString('b')),
            new IsIdentical(new TLiteralString($long_prefix . 'one')),
            new IsNotIdentical(new TLiteralString($long_prefix . 'two')),
            new IsIdentical(new TLiteralInt(5)),
            new IsNotIdentical(new TLiteralInt(5)),
            new IsLooselyEqual(new TLiteralInt(5)),
            new IsNotLooselyEqual(new TLiteralInt(5)),
            new IsAClass($named_object, false),
            new IsNotAClass($named_object, false),
            new IsNotAClass($other_named_object, false),
            new IsAClass($named_object, true),
            new IsClassEqual('Foo'),
            new IsClassNotEqual('Foo'),
            new InArray($int_array),
            new NotInArray($int_array),
            new IsGreaterThan(3),
            new IsLessThanOrEqualTo(3),
            new IsLessThan(3),
            new IsGreaterThanOrEqualTo(3),
            new HasAtLeastCount(2),
            new DoesNotHaveAtLeastCount(2),
            new HasExactCount(2),
            new DoesNotHaveExactCount(2),
            new HasMethod('foo'),
            new DoesNotHaveMethod('foo'),
            new IsCountable(),
            new IsNotCountable(true),
            new IsNotCountable(false),
            new NonEmptyCountable(true),
            new NonEmptyCountable(false),
            new NotNonEmptyCountable(),
            new ArrayKeyExists(),
            new ArrayKeyDoesNotExist(),
            new HasArrayKey('k'),
            new UnknownAssertion(),
        ];

        return $pool;
    }

    /**
     * @param list<Assertion> $pool
     * @return list<Clause>
     */
    private static function makeFormula(string $shape, array $pool): array
    {
        switch ($shape) {
            case 'small':
                $vars = self::makeVars(mt_rand(1, 3));
                $count = mt_rand(0, 12);
                break;
            case 'medium':
                $vars = self::makeVars(mt_rand(2, 6));
                $count = mt_rand(10, 60);
                break;
            case 'wide':
                $vars = self::makeVars(24);
                $count = mt_rand(280, 420);
                break;
            case 'unknown':
                $vars = ['*1:2', '*3:4', '*5:6', '$a'];
                $count = mt_rand(45, 70);
                break;
            default:
                return self::makeSwitchFormula($pool);
        }

        $clauses = [];

        for ($i = 0; $i < $count; $i++) {
            if ($clauses && mt_rand(1, 10) === 1) {
                $clauses[] = $clauses[mt_rand(0, count($clauses) - 1)];
                continue;
            }

            // an empty clause makes every other one redundant, which wide formulas must avoid
            if ($shape !== 'wide' && mt_rand(1, 50) === 1) {
                $clauses[] = new Clause([], mt_rand(1, 5), mt_rand(1, 50));
                continue;
            }

            if ($clauses && mt_rand(1, 6) === 1) {
                foreach (self::makeOpposingClauses($clauses[mt_rand(0, count($clauses) - 1)], $vars, $pool) as $clause) {
                    $clauses[] = $clause;
                }
                continue;
            }

            if ($shape === 'wide') {
                $var_count = 3;
            } else {
                $roll = mt_rand(1, 20);
                $var_count = $roll <= 12 ? 1 : ($roll <= 17 ? 2 : 3);
            }

            $clauses[] = self::makeClause($vars, $var_count, $pool, $shape === 'unknown');
        }

        return $clauses;
    }

    /**
     * Clauses that pass 1 and pass 3 act on: for $clause = (A || X), builds (!A || X) or (!A || Y),
     * sometimes followed by the resolvent (X || Y).
     *
     * @param list<string> $vars
     * @param list<Assertion> $pool
     * @return list<Clause>
     */
    private static function makeOpposingClauses(Clause $clause, array $vars, array $pool): array
    {
        foreach ($clause->possibilities as $var => $assertions) {
            if (count($assertions) !== 1) {
                continue;
            }

            try {
                $negation = reset($assertions)->getNegation();
            } catch (Throwable) {
                return [];
            }

            $rest = $clause->possibilities;
            unset($rest[$var]);

            if (mt_rand(0, 1) === 1) {
                $other = $rest;
            } else {
                $other = self::makeClause($vars, mt_rand(0, 2), $pool, false)->possibilities;
                unset($other[$var]);
            }

            $result = [new Clause([$var => [(string) $negation => $negation]] + $other, mt_rand(1, 5), mt_rand(1, 50))];

            if (mt_rand(0, 1) === 1 && ($rest || $other)) {
                $resolvent = $rest;
                foreach ($other as $other_var => $other_assertions) {
                    $resolvent[$other_var] = ($resolvent[$other_var] ?? []) + $other_assertions;
                }
                $result[] = new Clause($resolvent, mt_rand(1, 5), mt_rand(1, 50));
            }

            return $result;
        }

        return [];
    }

    /**
     * @param list<Assertion> $pool
     * @return list<Clause>
     */
    private static function makeSwitchFormula(array $pool): array
    {
        $clauses = [];
        $context_vars = self::makeVars(4);

        for ($i = mt_rand(0, 12); $i > 0; $i--) {
            $clauses[] = self::makeClause($context_vars, mt_rand(1, 2), $pool, false);
        }

        $count = mt_rand(40, 300);

        for ($i = 0; $i < $count; $i++) {
            $literal = new TLiteralString('case' . mt_rand(0, 350));
            $assertion = mt_rand(1, 40) === 1 ? new IsIdentical($literal) : new IsNotIdentical($literal);
            $clauses[] = new Clause(['$x' => [(string) $assertion => $assertion]], mt_rand(1, 3), mt_rand(1, 3));
        }

        return $clauses;
    }

    /**
     * @return list<string>
     */
    private static function makeVars(int $count): array
    {
        $all = ['$a', '$b', '$c', '$d[\'k\']', '$e->p', '*7:8', '$f', '$g', '$h', '$i', '$j', '$k', '$l',
            '$m', '$n', '$o', '$p', '$q', '$r', '$s', '$t', '$u', '$v', '$w'];

        $vars = [];

        for ($i = 0; $i < $count; $i++) {
            $vars[] = $all[mt_rand(0, count($all) - 1)];
        }

        return $vars;
    }

    /**
     * @param list<string> $vars
     * @param list<Assertion> $pool
     */
    private static function makeClause(array $vars, int $var_count, array $pool, bool $unknown_first): Clause
    {
        $possibilities = [];

        for ($v = 0; $v < $var_count; $v++) {
            $var = $unknown_first && $v === 0 ? '*1:2' : $vars[mt_rand(0, count($vars) - 1)];
            $var_possibilities = $possibilities[$var] ?? [];
            $assertion_count = mt_rand(1, 4) === 1 ? mt_rand(2, 3) : 1;

            do {
                $index = mt_rand(0, count($pool) - 1);
                // HasArrayKey (getNegation() throws) and the plugin assertion (no negation family),
                // the last two in the pool, take the exceptional paths, so keep them rare
                if ($index >= count($pool) - 2 && mt_rand(1, 20) !== 1) {
                    $index = 0;
                }
                $assertion = $pool[$index];
                $var_possibilities[(string) $assertion] = $assertion;
            } while (--$assertion_count > 0);

            $possibilities[$var] = $var_possibilities;
        }

        $roll = mt_rand(1, 50);

        return new Clause(
            $possibilities,
            mt_rand(1, 5),
            mt_rand(1, 50),
            $roll === 1,
            $roll !== 2,
            mt_rand(1, 3) === 1,
            mt_rand(1, 10) === 1 ? ['$a' => true] : [],
        );
    }

    /**
     * @param list<Clause> $clauses
     * @return list<Clause>|Throwable
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private static function simplify(bool $reference, array $clauses): array|Throwable
    {
        try {
            return $reference ? ReferenceAlgebra::simplifyCNF($clauses) : Algebra::simplifyCNF($clauses);
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * @param list<Clause>|Throwable $result
     * @param list<Clause> $inputs
     * @return list<array<array-key, mixed>>
     * @psalm-pure
     */
    private static function describe(array|Throwable $result, array $inputs): array
    {
        if ($result instanceof Throwable) {
            return [['exception', $result::class, $result->getMessage()]];
        }

        $input_positions = [];

        foreach ($inputs as $i => $input) {
            $input_positions[spl_object_id($input)] ??= $i;
        }

        $description = [];

        foreach ($result as $clause) {
            if (isset($input_positions[spl_object_id($clause)])) {
                $description[] = ['input', $input_positions[spl_object_id($clause)]];
                continue;
            }

            $possibilities = [];

            foreach ($clause->possibilities as $var => $assertions) {
                $possibilities[] = [
                    $var,
                    array_map(
                        static fn(Assertion $assertion): int => spl_object_id($assertion),
                        $assertions,
                    ),
                    array_keys($assertions),
                ];
            }

            $description[] = [
                'new',
                $clause->hash,
                $clause->creating_conditional_id,
                $clause->creating_object_id,
                $clause->wedge,
                $clause->reconcilable,
                $clause->generated,
                $clause->redefined_vars,
                array_keys($clause->redefined_vars),
                $possibilities,
                $clause->impossibilities,
            ];
        }

        return $description;
    }
}
