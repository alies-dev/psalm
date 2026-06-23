<?php

declare(strict_types=1);

namespace Psalm\Internal\Analyzer;

use Psalm\Codebase;
use Psalm\FileManipulation;
use Psalm\Internal\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\InvalidOverride;
use Psalm\Issue\MissingOverrideAttribute;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\PropertyStorage;

/**
 * Mirrors the method `#[\Override]` checks in {@see FunctionLikeAnalyzer} for properties.
 *
 * PHP 8.5 extended `#[\Override]` to target properties, so the same two diagnostics apply:
 *  - {@see MissingOverrideAttribute} when a property overrides a parent one but lacks the attribute
 *  - {@see InvalidOverride} when a property carries the attribute but overrides nothing
 *
 * Unlike the method check, this is gated to PHP 8.5+: property `#[\Override]` does not exist before
 * then, so reporting it on an older target would only push users towards code their engine rejects.
 *
 * @internal
 */
final class PropertyOverrideAnalyzer
{
    public static function analyze(
        StatementsSource $source,
        Codebase $codebase,
        ClassLikeStorage $class_storage,
        string $property_name,
        PropertyStorage $property_storage,
        bool $has_override_attribute,
        bool $can_apply_fix,
    ): void {
        if ($codebase->analysis_php_version_id < 8_05_00) {
            return;
        }

        // A trait property is intentionally not validated here: the attribute physically lives on the
        // shared trait, so there is no single using-class context to check it against (and no safe
        // place to apply the fix). This matches how Psalm already leaves trait methods unchecked.
        if ($class_storage->is_trait) {
            return;
        }

        $location = $property_storage->location;

        if ($location === null) {
            return;
        }

        $property_id = $class_storage->name . '::$' . $property_name;

        $overrides_parent = self::overridesParentProperty($codebase, $class_storage, $property_name);

        $suppressed_issues = $source->getSuppressedIssues() + $property_storage->suppressed_issues;

        if ($has_override_attribute) {
            if (!$overrides_parent) {
                IssueBuffer::maybeAdd(
                    new InvalidOverride(
                        'Property ' . $property_id . ' does not match any parent property',
                        $location,
                    ),
                    $suppressed_issues,
                );
            }

            return;
        }

        if (!$codebase->config->ensure_override_attribute || !$overrides_parent) {
            return;
        }

        IssueBuffer::maybeAdd(
            new MissingOverrideAttribute(
                'Property ' . $property_id . ' should have the "Override" attribute',
                $location,
            ),
            $suppressed_issues,
            true,
        );

        if (!$can_apply_fix
            || !$codebase->alter_code
            || $property_storage->stmt_location === null
            || !isset(ProjectAnalyzer::getInstance()->getIssuesToFix()['MissingOverrideAttribute'])
        ) {
            return;
        }

        $idx = $property_storage->stmt_location->getSelectionBounds()[0];

        // A promoted property is part of a parameter list, so the attribute goes inline before it
        // (a trailing space, no indentation handling). A regular property gets the attribute on its
        // own line above, with the existing indentation reapplied afterwards.
        $is_promoted = $property_storage->is_promoted;
        $insertion_text = $is_promoted ? '#[\\Override] ' : "#[\\Override]\n";

        FileManipulationBuffer::add($property_storage->stmt_location->file_path, [
            new FileManipulation($idx, $idx, $insertion_text, !$is_promoted),
        ]);
    }

    private static function overridesParentProperty(
        Codebase $codebase,
        ClassLikeStorage $class_storage,
        string $property_name,
    ): bool {
        // Parent classes are tracked here by the Populator (private parents are excluded, matching
        // the engine, which does not treat a private parent property as overridable).
        if (isset($class_storage->overridden_property_ids[$property_name])) {
            return true;
        }

        // Interface properties (abstract property hooks, PHP 8.4+) are not copied into the
        // implementing class, so check the implemented interfaces directly. This keeps property
        // handling consistent with methods, whose getOverriddenMethodIds() already spans interfaces.
        foreach ($class_storage->class_implements as $interface_fqcln) {
            if (!$codebase->classlike_storage_provider->has($interface_fqcln)) {
                continue;
            }

            $interface_storage = $codebase->classlike_storage_provider->get($interface_fqcln);

            if (isset($interface_storage->properties[$property_name])
                && $interface_storage->properties[$property_name]->visibility
                    !== ClassLikeAnalyzer::VISIBILITY_PRIVATE
            ) {
                return true;
            }
        }

        return false;
    }
}
