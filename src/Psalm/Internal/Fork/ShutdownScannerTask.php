<?php

declare(strict_types=1);

namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Override;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Codebase\Scanner;
use Psalm\IssueBuffer;

use function array_diff_key;

use const PHP_EOL;

/**
 * Sends back what the process changed since it was forked (see InheritedScanState)
 *
 * @internal
 * @psalm-import-type PoolData from Scanner
 * @implements Task<PoolData, void, void>
 */
final class ShutdownScannerTask implements Task
{
    /**
     * @return PoolData
     */
    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $project_analyzer = ProjectAnalyzer::getInstance();
        $project_analyzer->progress->debug('Collecting data from forked scanner process' . PHP_EOL);

        $codebase = $project_analyzer->getCodebase();
        $statements_provider = $codebase->statements_provider;
        $inherited = InheritedScanState::get();
        $classlike_storage = $codebase->classlike_storage_provider->getAll();
        $taint_flow_graph = $codebase->taint_flow_graph;

        return [
            'classlikes_data' => $codebase->classlikes->getThreadDataSince($inherited['classlikes_data']),
            'scanner_data' => $codebase->scanner->getThreadDataSince($inherited['scanner_data'], $classlike_storage),
            'issues' => self::getChangedIssues(IssueBuffer::getIssuesData(), $inherited['issues']),
            'changed_members' => $statements_provider->getChangedMembers(),
            'unchanged_signature_members' => $statements_provider->getUnchangedSignatureMembers(),
            'diff_map' => $statements_provider->getDiffMap(),
            'deletion_ranges' => $statements_provider->getDeletionRanges(),
            'errors' => array_diff_key($statements_provider->getErrors(), $inherited['errors']),
            'classlike_storage' => $classlike_storage,
            'file_storage' => $codebase->file_storage_provider->getAll(),
            'taint_data' => $taint_flow_graph !== null && (array) $taint_flow_graph !== $inherited['taint_data']
                ? $taint_flow_graph
                : null,
            'global_registrations' => $codebase->scanner->getGlobalRegistrations(),
        ];
    }

    /**
     * The issues of the files that got new ones: the parent skips those it has already
     *
     * @param array<string, list<IssueData>> $issues
     * @param array<string, list<IssueData>> $inherited
     * @return array<string, list<IssueData>>
     * @psalm-pure
     */
    private static function getChangedIssues(array $issues, array $inherited): array
    {
        $changed = [];

        foreach ($issues as $file_path => $file_issues) {
            if (($inherited[$file_path] ?? null) !== $file_issues) {
                $changed[$file_path] = $file_issues;
            }
        }

        return $changed;
    }
}
