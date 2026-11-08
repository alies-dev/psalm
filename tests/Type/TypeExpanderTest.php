<?php

declare(strict_types=1);

namespace Psalm\Tests\Type;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Tests\TestCase;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntMask;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNever;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\Union;

final class TypeExpanderTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): non-empty-list<Atomic>, string}>
     */
    public static function providerExpandUnion(): iterable
    {
        yield 'int' => [static fn(): array => [new TInt()], 'int'];
        yield 'literal ints' => [static fn(): array => [new TLiteralInt(2), new TLiteralInt(1)], '1|2'];
        yield 'int range' => [static fn(): array => [new TIntRange(0, 5)], 'int<0, 5>'];
        yield 'int absorbing its literals'
            => [static fn(): array => [new TLiteralInt(1), new TInt(), new TIntRange(0, 5)], 'int'];
        yield 'float absorbing its literals' => [static fn(): array => [new TLiteralFloat(1.5), new TFloat()], 'float'];
        yield 'string absorbing its literals'
            => [static fn(): array => [new TLiteralString('a'), new TString()], 'string'];
        yield 'literal strings'
            => [static fn(): array => [new TLiteralString('a'), new TLiteralString('b')], "'a'|'b'"];
        yield 'true and false' => [static fn(): array => [new TTrue(), new TFalse()], 'bool'];
        yield 'bool absorbing true' => [static fn(): array => [new TTrue(), new TBool()], 'bool'];
        yield 'null' => [static fn(): array => [new TNull(), new TInt()], 'int|null'];
        yield 'mixed next to int' => [static fn(): array => [new TInt(), new TMixed()], 'int|mixed'];
        yield 'void' => [static fn(): array => [new TVoid()], 'void'];
        yield 'never' => [static fn(): array => [new TNever()], 'never'];
        yield 'with an int mask' => [
            static fn(): array => [new TIntMask([new TLiteralInt(1), new TLiteralInt(2)]), new TNull()],
            '0|1|2|3|null',
        ];
        yield 'with a class string' => [static fn(): array => [new TClassString(), new TString()], 'string'];
        yield 'with a non-empty string'
            => [static fn(): array => [new TNonEmptyString(), new TLiteralString('a')], 'non-empty-string'];
    }

    /**
     * @param Closure(): non-empty-list<Atomic> $atomics
     */
    #[DataProvider('providerExpandUnion')]
    public function testExpandUnion(Closure $atomics, string $expected): void
    {
        $codebase = $this->project_analyzer->getCodebase();

        $expanded = TypeExpander::expandUnion($codebase, new Union($atomics()), null, null, null);

        $this->assertSame($expected, $expanded->getId());
    }

    public function testExpandUnionKeepsTheAtomicsAndCopiesTheFlags(): void
    {
        $codebase = $this->project_analyzer->getCodebase();
        $int = new TInt();

        $type = new Union([$int], [
            'from_docblock' => true,
            'ignore_nullable_issues' => true,
            'ignore_falsable_issues' => true,
            'possibly_undefined' => true,
            'by_ref' => true,
            'initialized' => false,
            'had_template' => true,
            'checked' => true,
            'from_calculation' => true,
        ]);

        $expanded = TypeExpander::expandUnion($codebase, $type, null, null, null);

        $this->assertNotSame($type, $expanded);
        $this->assertSame($int, $expanded->getSingleAtomic());
        $this->assertTrue($expanded->from_docblock);
        $this->assertTrue($expanded->ignore_nullable_issues);
        $this->assertTrue($expanded->ignore_falsable_issues);
        $this->assertTrue($expanded->possibly_undefined);
        $this->assertTrue($expanded->by_ref);
        $this->assertFalse($expanded->initialized);
        $this->assertTrue($expanded->had_template);
        $this->assertFalse($expanded->checked);
        $this->assertFalse($expanded->from_calculation);
    }

    public function testExpandUnionKeepsTheBitsOfAnIntMaskNextToScalars(): void
    {
        $codebase = $this->project_analyzer->getCodebase();

        $expanded = TypeExpander::expandUnion(
            $codebase,
            new Union([new TNull(), new TIntMask([new TLiteralInt(1), new TLiteralInt(4)])]),
            null,
            null,
            null,
        );

        $this->assertSame(5, $expanded->int_mask_bits);
    }
}
