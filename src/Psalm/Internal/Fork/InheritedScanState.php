<?php

declare(strict_types=1);

namespace Psalm\Internal\Fork;

use LogicException;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Codebase\Scanner;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\Internal\Provider\FileStorageProvider;
use Psalm\Internal\Scanner\UnresolvedConstantComponent;
use Psalm\IssueBuffer;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionStorage;
use Psalm\Type\Union;

/**
 * The state a scanning process was forked with: it sends the parent back only what changed since.
 *
 * Taking it costs nothing, as the arrays are shared until the process changes them. The process scans as if it knew
 * no storages, as the parent merges every process's own; the inherited ones are kept here rather than freed, as
 * freeing them would write to each, copying the memory it shares with the parent.
 *
 * @internal
 * @psalm-import-type ThreadData from Scanner
 * @psalm-import-type ThreadData from ClassLikes as ClassLikesThreadData
 * @psalm-type Inherited = array{
 *     file_storage: array<lowercase-string, FileStorage>,
 *     classlike_storage: list{array<string, ClassLikeStorage>, array<string, ClassLikeStorage>},
 *     classlikes_data: ClassLikesThreadData,
 *     scanner_data: ThreadData,
 *     issues: array<string, list<IssueData>>,
 *     errors: array<string, bool>,
 *     taint_data: array<array-key, mixed>|null,
 *     global_constants: array<string, Union>,
 *     unresolved_global_constants: array<string, UnresolvedConstantComponent>,
 *     global_functions: array<lowercase-string, FunctionStorage>,
 * }
 */
final class InheritedScanState
{
    /**
     * @var Inherited|null
     */
    private static ?array $inherited = null;

    /**
     * @psalm-capabilities read-props|write-this-props|write-props|read-globals|write-globals
     */
    public static function take(Codebase $codebase): void
    {
        $taint_flow_graph = $codebase->taint_flow_graph;

        self::$inherited = [
            'file_storage' => FileStorageProvider::detachAll(),
            'classlike_storage' => ClassLikeStorageProvider::detachAll(),
            'classlikes_data' => $codebase->classlikes->getThreadData(),
            'scanner_data' => $codebase->scanner->getThreadData(),
            'issues' => IssueBuffer::getIssuesData(),
            'errors' => $codebase->statements_provider->getErrors(),
            // its properties, all arrays: one the graph adds to is copied first, so it is no longer the same array
            'taint_data' => $taint_flow_graph === null ? null : (array) $taint_flow_graph,
            'global_constants' => $codebase->getAllStubbedConstants(),
            'unresolved_global_constants' => $codebase->getAllUnresolvedGlobalConstants(),
            'global_functions' => $codebase->functions->getAllStubbedFunctions(),
        ];
    }

    /**
     * @return Inherited
     * @psalm-capabilities read-globals
     */
    public static function get(): array
    {
        if (self::$inherited === null) {
            throw new LogicException('The process was not initialised for scanning');
        }

        return self::$inherited;
    }
}
