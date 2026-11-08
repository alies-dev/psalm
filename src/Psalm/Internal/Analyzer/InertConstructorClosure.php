<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer;

use PhpParser;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Codebase\CodeUseGraph;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\PhpVisitor\InertBodyVisitor;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\Internal\TypeVisitor\ContainsStaticVisitor;
use Psalm\IssueBuffer;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Union;

use function array_diff_key;
use function array_keys;
use function array_pop;
use function explode;
use function ltrim;
use function sort;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * The method bodies the initialization pass of a class without a constructor of its own analyses again for the
 * constructor it inherits ({@see ClassAnalyzer::checkPropertyInitialization()}): the inherited constructor, and
 * the methods it calls on `$this` or statically on the classes it extends, resolved for the class.
 *
 * When those bodies are all in files where no issue can be reported, and can't set or name a property the pass
 * checks, nothing the pass reports depends on them: it skips them ({@see self::ATTRIBUTE}). That also requires
 * that nothing else observes their analysis: no plugin hook, no reference into the project for unused code
 * detection, no reference lookup, no code alteration.
 *
 * @psalm-type MethodSummary = array{
 *     this_calls: array<lowercase-string, true>,
 *     properties: array<string, true>,
 *     static_calls: list<array{string, lowercase-string}>,
 *     classes: array<string, true>,
 * }
 * @internal
 */
final class InertConstructorClosure
{
    /**
     * The attribute of the call to the inherited constructor whose bodies the pass skips: the closure
     */
    public const ATTRIBUTE = 'psalm-inert-constructor-closure';

    /**
     * @param list<lowercase-string> $this_method_ids the methods the bodies call on `$this`, as `Class::method`
     * @param array<string, true> $property_names the properties the bodies fetch on `$this`
     * @param array<string, true> $file_paths the files of the bodies
     * @psalm-mutation-free
     */
    private function __construct(
        public readonly string $class_name,
        public readonly array $this_method_ids,
        public readonly array $property_names,
        public readonly array $file_paths,
    ) {
    }

    /**
     * @param array<string, true> $checked_property_names the properties whose initialization the pass checks
     */
    public static function find(
        Codebase $codebase,
        ClassLikeStorage $class_storage,
        MethodIdentifier $constructor_id,
        array $checked_property_names,
        Context $class_context,
    ): ?self {
        if ($codebase->alter_code
            || $codebase->collect_locations
            || $codebase->config->eventDispatcher->hasStatementAnalysisHooks()
            || ($class_storage->template_types ?? []) !== []
            || ($class_storage->template_extended_params ?? []) !== []
        ) {
            return null;
        }

        // the pass leaves a property it checks either unchanged or removed, which mean the same only when it
        // starts out uninitialized
        foreach ($checked_property_names as $property_name => $_) {
            $property_type = $class_context->vars_in_scope['$this->' . $property_name] ?? null;

            if ($property_type === null
                || $property_type->initialized !== false
                || $property_type->initialized_class !== null
            ) {
                return null;
            }
        }

        foreach (['__get', '__set', '__isset', '__unset', '__call'] as $magic_method_name) {
            if (isset($class_storage->declaring_method_ids[$magic_method_name])
                && !self::isUnobservable(
                    $codebase,
                    $class_storage->declaring_method_ids[$magic_method_name]->fq_class_name,
                )
            ) {
                return null;
            }
        }

        $classlike_storage_provider = $codebase->classlike_storage_provider;
        $methods = $codebase->methods;

        $queue = [$constructor_id];
        $seen = [];
        $this_method_ids = [];
        $property_names = [];
        $file_paths = [];

        while ($queue) {
            $method_id = array_pop($queue);
            $method_id_lc = strtolower((string) $method_id);

            if (isset($seen[$method_id_lc])) {
                continue;
            }

            $seen[$method_id_lc] = true;

            $body_class_storage = $classlike_storage_provider->get($method_id->fq_class_name);

            // `parent::__construct()` into a stubbed class initializes the properties of the class
            // (see StaticCallAnalyzer)
            if ($body_class_storage->stubbed) {
                return null;
            }

            if (!$body_class_storage->user_defined) {
                // no body to analyse
                continue;
            }

            if ($body_class_storage->is_trait
                || $body_class_storage->used_traits
                || ($body_class_storage->template_types ?? []) !== []
                || !$body_class_storage->location
                || !self::isUnobservable($codebase, $body_class_storage->name)
            ) {
                return null;
            }

            $method_storage = $methods->getStorage($method_id);

            if (($method_storage->template_types ?? []) !== []
                || $method_storage->self_out_type
                || $method_storage->if_this_is_type
                || $method_storage->assertions
                || $method_storage->if_true_assertions
                || $method_storage->if_false_assertions
                || ($method_storage->return_type && self::hasStatic($method_storage->return_type))
            ) {
                return null;
            }

            foreach ($method_storage->params as $param) {
                if ($param->promoted_property) {
                    $property_names[$param->name] = true;
                }
            }

            $file_path = $body_class_storage->location->file_path;

            $summary = self::getSummary($codebase, $file_path, $method_id_lc);

            if ($summary === null) {
                return null;
            }

            $file_paths[$file_path] = true;
            $property_names += $summary['properties'];

            foreach ($summary['classes'] as $fq_class_name => $_) {
                if ($classlike_storage_provider->has($fq_class_name)
                    && !self::isUnobservable($codebase, $fq_class_name)
                ) {
                    return null;
                }
            }

            foreach ($summary['this_calls'] as $method_name => $_) {
                $called_method_id = new MethodIdentifier($class_storage->name, $method_name);

                // a private method of the body's class is called if the class has none
                $declaring_method_id = $methods->getDeclaringMethodId($called_method_id)
                    ?? $methods->getDeclaringMethodId(new MethodIdentifier($body_class_storage->name, $method_name));

                if ($declaring_method_id === null) {
                    return null;
                }

                $appearing_method_id = $methods->getAppearingMethodId($called_method_id);

                if ($appearing_method_id !== null
                    && !self::isUnobservable($codebase, $appearing_method_id->fq_class_name)
                ) {
                    return null;
                }

                // the call references the method in these classes too: one declared in the project would be used
                $referenced_class_names = array_keys($class_storage->class_implements);

                foreach ($class_storage->potential_declaring_method_ids[$method_name] ?? [] as $potential_id => $_) {
                    $referenced_class_names[] = explode('::', $potential_id)[0];
                }

                foreach ($referenced_class_names as $referenced_class_name) {
                    if (isset($classlike_storage_provider->get($referenced_class_name)->methods[$method_name])
                        && !self::isUnobservable($codebase, $referenced_class_name)
                    ) {
                        return null;
                    }
                }

                $this_method_ids[] = strtolower($class_storage->name) . '::' . $method_name;
                $queue[] = $declaring_method_id;
            }

            foreach ($summary['static_calls'] as [$fq_class_name, $method_name]) {
                if (strtolower($fq_class_name) === 'self') {
                    $fq_class_name = $body_class_storage->name;
                } elseif (strtolower($fq_class_name) === 'parent') {
                    if ($body_class_storage->parent_class === null) {
                        continue;
                    }

                    $fq_class_name = $body_class_storage->parent_class;
                } elseif (!$classlike_storage_provider->has($fq_class_name)) {
                    continue;
                }

                $called_method_id = new MethodIdentifier($fq_class_name, $method_name);
                $declaring_method_id = $methods->getDeclaringMethodId($called_method_id);
                $appearing_method_id = $methods->getAppearingMethodId($called_method_id);

                if ($declaring_method_id === null || $appearing_method_id === null) {
                    continue;
                }

                $appearing_class_lc = strtolower($appearing_method_id->fq_class_name);

                // only a call into the body's class or a class it extends is analysed in the pass
                if ($appearing_class_lc === strtolower($body_class_storage->name)
                    || isset($body_class_storage->parent_classes[$appearing_class_lc])
                ) {
                    $queue[] = $declaring_method_id;
                }
            }
        }

        foreach ($property_names as $property_name => $_) {
            if (isset($checked_property_names[$property_name])) {
                return null;
            }

            if (isset($class_storage->declaring_property_ids[$property_name])
                && !self::isUnobservable($codebase, $class_storage->declaring_property_ids[$property_name])
            ) {
                return null;
            }

            foreach ([strtolower($class_storage->name) => true] + $class_storage->parent_classes as $ancestor => $_) {
                if (isset($classlike_storage_provider->get($ancestor)->properties[$property_name])
                    && !self::isUnobservable($codebase, $ancestor)
                ) {
                    return null;
                }
            }
        }

        return new self($class_storage->name, $this_method_ids, $property_names, $file_paths);
    }

    /**
     * Records the references the pass records from the constructor of the class through the bodies to the class and
     * its members: in `--diff` mode, they get the pass run again when one of those is added or changes
     */
    public function addReferences(Codebase $codebase, Context $context): void
    {
        foreach ($this->this_method_ids as $method_id) {
            $codebase->addReferenceToFunctionLike($method_id, null, $context);
        }

        if (!$this->property_names) {
            return;
        }

        $class_name_lc = strtolower($this->class_name);
        $class_storage = $codebase->classlike_storage_provider->get($class_name_lc);

        $codebase->addReferenceToClass($class_name_lc, null, $context);

        foreach ($this->property_names as $property_name => $_) {
            if (isset($class_storage->declaring_property_ids[$property_name])) {
                $codebase->addReferenceToProperty(
                    strtolower($class_storage->declaring_property_ids[$property_name]),
                    $property_name,
                    true,
                    null,
                    $context,
                );
            } else {
                $codebase->addReferenceToMissingProperty($class_name_lc, $property_name, null, $context);
                $codebase->addReferenceToMissingMethod($class_name_lc . '::__get', null, $context);
                $codebase->addReferenceToMissingMethod($class_name_lc . '::__set', null, $context);
            }
        }
    }

    /**
     * Runs the pass with and without the bodies, and checks that it can't tell: it must leave the properties it
     * checks in the same state and report the same issues, none in the files of the bodies, reference the same
     * classes and members of the project, and the bodies mustn't add names of members used dynamically. Only meant
     * for an assert().
     *
     * @param array<string, true> $checked_property_names
     */
    public function isSkipExact(
        ClassAnalyzer $class_analyzer,
        PhpParser\Node\Stmt\ClassMethod $constructor,
        PhpParser\Node\Expr\StaticCall $constructor_call,
        Context $method_context,
        ?Context $global_context,
        array $checked_property_names,
    ): bool {
        $skipped = $this->analyzeConstructor(
            $class_analyzer,
            $constructor,
            $constructor_call,
            $method_context,
            $global_context,
            $checked_property_names,
            true,
        );

        $mixed_member_names = $class_analyzer->getCodebase()->analyzer->getMixedMemberNames();

        $analyzed = $this->analyzeConstructor(
            $class_analyzer,
            $constructor,
            $constructor_call,
            $method_context,
            $global_context,
            $checked_property_names,
            false,
        );

        $constructor_call->setAttribute(self::ATTRIBUTE, $this);

        return $skipped !== null
            && $skipped === $analyzed
            && !array_diff_key($class_analyzer->getCodebase()->analyzer->getMixedMemberNames(), $mixed_member_names);
    }

    /**
     * @param array<string, true> $checked_property_names
     * @return list<string>|null what the pass can observe, null if it reported an issue in the files of the bodies
     */
    private function analyzeConstructor(
        ClassAnalyzer $class_analyzer,
        PhpParser\Node\Stmt\ClassMethod $constructor,
        PhpParser\Node\Expr\StaticCall $constructor_call,
        Context $method_context,
        ?Context $global_context,
        array $checked_property_names,
        bool $skip,
    ): ?array {
        $codebase = $class_analyzer->getCodebase();
        $file_path = $class_analyzer->getFilePath();
        $mixed_counts = $codebase->analyzer->getMixedCountsForFile($file_path);
        $constructor_node = CodeUseGraph::functionLikeNode((string) $method_context->calling_method_id);

        $constructor_call->setAttribute(self::ATTRIBUTE, $skip ? $this : null);
        $codebase->code_use_graph->removeReferencesFrom($constructor_node);

        $context = clone $method_context;

        IssueBuffer::startRecording();
        (new MethodAnalyzer($constructor, $class_analyzer))
            ->analyze($context, new NodeDataProvider(), $global_context, true);
        $issues = IssueBuffer::clearRecordingLevel();
        IssueBuffer::stopRecording();

        $codebase->analyzer->setMixedCountsForFile($file_path, $mixed_counts);

        $observed = [];

        foreach ($checked_property_names as $property_name => $_) {
            $property_type = $context->vars_in_scope['$this->' . $property_name] ?? null;
            $observed[] = $property_name . ($property_type && $property_type->initialized
                ? ' initialized by ' . ($property_type->initialized_class ?? '')
                : ' uninitialized');
        }

        foreach ($issues as $issue) {
            if (isset($this->file_paths[$issue->getFilePath()])) {
                return null;
            }

            $observed[] = $issue::class . ' ' . $issue->getFilePath() . ':' . $issue->code_location->raw_file_start
                . ' ' . $issue->message;
        }

        foreach ($codebase->code_use_graph->getReferencedNodes($constructor_node) as $node_id => $_) {
            if (self::isProjectClassOrMember($codebase, $node_id)) {
                $observed[] = $node_id;
            }
        }

        sort($observed);

        return $observed;
    }

    /**
     * Whether the node is a class of the project or a member a class of the project declares: those unused code
     * detection checks
     */
    private static function isProjectClassOrMember(Codebase $codebase, string $node_id): bool
    {
        $owner_class = CodeUseGraph::getOwnerClass($node_id);

        if ($owner_class === null
            || !$codebase->classlike_storage_provider->has($owner_class)
            || self::isUnobservable($codebase, $owner_class)
        ) {
            return false;
        }

        $member_id = CodeUseGraph::getMemberId($node_id);

        if ($member_id === null) {
            return str_starts_with($node_id, 'class ');
        }

        if (str_starts_with($node_id, 'missing-')) {
            return false;
        }

        $owner_storage = $codebase->classlike_storage_provider->get($owner_class);
        $member_name = explode('::', $member_id, 2)[1] ?? '';

        return str_starts_with($member_name, '$')
            ? isset($owner_storage->properties[substr($member_name, 1)])
            : isset($owner_storage->methods[strtolower($member_name)])
                || isset($owner_storage->constants[$member_name]);
    }

    /**
     * Whether the analysis of code in the class can't be observed: no issue can be reported in its file, and it
     * isn't part of the project, whose members unused code detection checks
     */
    private static function isUnobservable(Codebase $codebase, string $fq_class_name): bool
    {
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);

        if (!$class_storage->location) {
            return !$class_storage->user_defined;
        }

        $file_path = $class_storage->location->file_path;

        return !$codebase->config->isInProjectDirs($file_path)
            && !$codebase->config->canReportIssuesInFile($file_path);
    }

    /**
     * @psalm-pure
     */
    private static function hasStatic(Union $type): bool
    {
        $visitor = new ContainsStaticVisitor();
        $visitor->traverse($type);

        return $visitor->matches();
    }

    /**
     * @param lowercase-string $method_id_lc
     * @return MethodSummary|null
     */
    private static function getSummary(Codebase $codebase, string $file_path, string $method_id_lc): ?array
    {
        if (!isset($codebase->inert_constructor_summaries[$file_path])) {
            $codebase->inert_constructor_summaries[$file_path] = self::summarizeFile($codebase, $file_path);
        }

        return $codebase->inert_constructor_summaries[$file_path][$method_id_lc] ?? null;
    }

    /**
     * @return array<lowercase-string, MethodSummary|null>
     */
    private static function summarizeFile(Codebase $codebase, string $file_path): array
    {
        $summaries = [];

        foreach ($codebase->getStatementsForFile($file_path) as $stmt) {
            if ($stmt instanceof PhpParser\Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $namespace_stmt) {
                    if ($namespace_stmt instanceof PhpParser\Node\Stmt\Class_) {
                        self::summarizeClass($codebase, $namespace_stmt, (string) $stmt->name, $summaries);
                    }
                }
            } elseif ($stmt instanceof PhpParser\Node\Stmt\Class_) {
                self::summarizeClass($codebase, $stmt, '', $summaries);
            }
        }

        return $summaries;
    }

    /**
     * @param array<lowercase-string, MethodSummary|null> $summaries
     */
    private static function summarizeClass(
        Codebase $codebase,
        PhpParser\Node\Stmt\Class_ $class,
        string $namespace,
        array &$summaries,
    ): void {
        if ($class->name === null) {
            return;
        }

        $fq_class_name = ($namespace !== '' ? $namespace . '\\' : '') . $class->name->name;

        if (!$codebase->classlike_storage_provider->has($fq_class_name)) {
            return;
        }

        $aliases = $codebase->classlike_storage_provider->get($fq_class_name)->aliases ?? new Aliases($namespace);

        foreach ($class->getMethods() as $method) {
            $method_id_lc = strtolower($fq_class_name . '::' . $method->name->name);

            $visitor = new InertBodyVisitor();
            $traverser = new PhpParser\NodeTraverser($visitor);
            $traverser->traverse($method->stmts ?? []);

            if (!$visitor->inert) {
                $summaries[$method_id_lc] = null;
                continue;
            }

            $classes = [];

            foreach ($visitor->class_names as $class_name) {
                $classes[ClassLikeAnalyzer::getFQCLNFromNameObject($class_name, $aliases)] = true;
            }

            foreach ($visitor->class_strings as $class_string => $_) {
                $classes[explode('::', ltrim($class_string, '\\'))[0]] = true;
            }

            $static_calls = [];

            foreach ($visitor->static_calls as [$class_name, $method_name]) {
                $static_calls[] = [ClassLikeAnalyzer::getFQCLNFromNameObject($class_name, $aliases), $method_name];
            }

            $summaries[$method_id_lc] = [
                'this_calls' => $visitor->this_calls,
                'properties' => $visitor->properties,
                'static_calls' => $static_calls,
                'classes' => $classes,
            ];
        }
    }
}
