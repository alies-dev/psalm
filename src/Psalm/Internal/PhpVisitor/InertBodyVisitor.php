<?php

declare(strict_types=1);

namespace Psalm\Internal\PhpVisitor;

use Override;
use PhpParser;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;

use function is_string;
use function preg_match;
use function spl_object_id;
use function strtolower;

/**
 * Collects what a method body can do to `$this` and which members and classes it names, for
 * {@see \Psalm\Internal\Analyzer\InertConstructorClosure}. A body using `$this` or naming a member in a way the
 * summary can't describe is marked not inert.
 *
 * @internal
 */
final class InertBodyVisitor extends PhpParser\NodeVisitorAbstract
{
    public bool $inert = true;

    /** @var array<lowercase-string, true> the methods called on `$this` */
    public array $this_calls = [];

    /** @var array<string, true> the properties fetched on `$this` */
    public array $properties = [];

    /** @var list<array{Name, lowercase-string}> */
    public array $static_calls = [];

    /** @var list<Name> the class names, except self, parent and static */
    public array $class_names = [];

    /** @var array<string, true> the string literals that can name a class or a method */
    public array $class_strings = [];

    /** @var array<int, true> the nodes that are where they are allowed: `$this` receivers, function names */
    private array $allowed = [];

    #[Override]
    public function enterNode(PhpParser\Node $node): ?int
    {
        if ($node->getDocComment() !== null) {
            return $this->notInert();
        }

        if (isset($this->allowed[spl_object_id($node)])) {
            return null;
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            if (!$node->name instanceof PhpParser\Node\Identifier || !self::isThis($node->var)) {
                return $this->notInert();
            }

            $this->allowed[spl_object_id($node->var)] = true;
            $this->this_calls[strtolower($node->name->name)] = true;
        } elseif ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            if (!$node->name instanceof PhpParser\Node\Identifier || !self::isThis($node->var)) {
                return $this->notInert();
            }

            $this->allowed[spl_object_id($node->var)] = true;
            $this->properties[$node->name->name] = true;
        } elseif ($node instanceof Expr\Variable) {
            // a bare `$this` can reach any property, a variable variable any variable
            if (!is_string($node->name) || $node->name === 'this') {
                return $this->notInert();
            }
        } elseif ($node instanceof Expr\StaticCall) {
            if (!$node->class instanceof Name
                || !$node->name instanceof PhpParser\Node\Identifier
                || $node->class->toLowerString() === 'static'
            ) {
                return $this->notInert();
            }

            $this->static_calls[] = [$node->class, strtolower($node->name->name)];
        } elseif ($node instanceof Expr\StaticPropertyFetch) {
            if (!$node->class instanceof Name
                || !$node->name instanceof PhpParser\Node\VarLikeIdentifier
                || $node->class->toLowerString() === 'static'
            ) {
                return $this->notInert();
            }
        } elseif ($node instanceof Expr\ClassConstFetch) {
            if (!$node->class instanceof Name
                || !$node->name instanceof PhpParser\Node\Identifier
                || ($node->class->toLowerString() === 'static' && strtolower($node->name->name) !== 'class')
            ) {
                return $this->notInert();
            }
        } elseif ($node instanceof Expr\New_ || $node instanceof Expr\Instanceof_) {
            if (!$node->class instanceof Name || $node->class->toLowerString() === 'static') {
                return $this->notInert();
            }
        } elseif ($node instanceof Expr\FuncCall) {
            if (!$node->name instanceof Name) {
                return $this->notInert();
            }

            $this->allowed[spl_object_id($node->name)] = true;
        } elseif ($node instanceof Expr\ConstFetch) {
            $this->allowed[spl_object_id($node->name)] = true;
        } elseif ($node instanceof Expr\Include_
            || $node instanceof Expr\Eval_
            || $node instanceof PhpParser\Node\Stmt\ClassLike
        ) {
            return $this->notInert();
        } elseif ($node instanceof Name) {
            $name_lc = $node->toLowerString();

            if ($name_lc !== 'self' && $name_lc !== 'parent' && $name_lc !== 'static') {
                $this->class_names[] = $node;
            }
        } elseif ($node instanceof String_
            && preg_match('/^\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff\\\\]*(::[A-Za-z_\x80-\xff][\w\x80-\xff]*)?$/', $node->value)
        ) {
            $this->class_strings[$node->value] = true;
        }

        return null;
    }

    /**
     * @psalm-capabilities read-props
     */
    private static function isThis(Expr $expr): bool
    {
        return $expr instanceof Expr\Variable && $expr->name === 'this';
    }

    /**
     * @psalm-external-mutation-free
     */
    private function notInert(): int
    {
        $this->inert = false;

        return PhpParser\NodeVisitor::STOP_TRAVERSAL;
    }
}
