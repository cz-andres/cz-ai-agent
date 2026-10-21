<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

use InvalidArgumentException;

final class SchemaRelationshipProfilingService
{
    public function __construct(
        private readonly SchemaRelationshipAnalyzer $analyzer,
        private readonly RelationshipProfilingService $profiler,
        private readonly RelationshipConfidenceScorer $scorer = new RelationshipConfidenceScorer(),
        private readonly RelationshipProfilingDiagnostics $diagnostics = new RelationshipProfilingDiagnostics(),
    ) {
    }

    /**
     * Analyse relationships and profile a bounded number of candidates.
     *
     * @param array{
     *     database: string,
     *     tables: list<array<string, mixed>>,
     *     columns: list<array<string, mixed>>,
     *     foreign_keys: list<array<string, mixed>>
     * } $schema
     * @return array{
     *     declared_relationships: list<array<string, mixed>>,
     *     inferred_candidates: list<array<string, mixed>>
     * }
     */
    public function analyzeAndProfile(
        array $schema,
        int $sampleLimit = 100,
        int $maxDeclaredToProfile = 20,
        int $maxInferredToProfile = 20,
    ): array {
        if ($sampleLimit < 1 || $sampleLimit > 5000) {
            throw new InvalidArgumentException(
                'Sample limit must be between 1 and 5000.'
            );
        }

        foreach ([
                     'maxDeclaredToProfile' => $maxDeclaredToProfile,
                     'maxInferredToProfile' => $maxInferredToProfile,
                 ] as $name => $limit) {
            if ($limit < 0 || $limit > 500) {
                throw new InvalidArgumentException(
                    "{$name} must be between 0 and 500."
                );
            }
        }

        $analysis = $this->analyzer->analyze($schema);

        return [
            'declared_relationships' => $this->profileGroup(
                $analysis['declared_relationships'],
                $schema,
                $sampleLimit,
                $maxDeclaredToProfile,
                false,
            ),
            'inferred_candidates' => $this->profileGroup(
                $analysis['inferred_candidates'],
                $schema,
                $sampleLimit,
                $maxInferredToProfile,
                true,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $schema
     * @return list<array<string, mixed>>
     */
    private function profileGroup(
        array $candidates,
        array $schema,
        int $sampleLimit,
        int $maxToProfile,
        bool $rankBeforeProfiling,
    ): array {
        $ambiguityCounts = [];

        if ($rankBeforeProfiling) {
            $targetsBySource = [];

            foreach ($candidates as $candidate) {
                $sourceKey = json_encode([
                    $candidate['source_table'],
                    $candidate['source_column'],
                ], JSON_THROW_ON_ERROR);

                $targetKey = json_encode([
                    $candidate['target_table'],
                    $candidate['target_column'],
                ], JSON_THROW_ON_ERROR);

                $targetsBySource[$sourceKey][$targetKey] = true;
            }

            foreach ($targetsBySource as $sourceKey => $targets) {
                $ambiguityCounts[$sourceKey] = count($targets);
            }
        }

        $ranked = [];

        foreach ($candidates as $originalIndex => $candidate) {
            if ($rankBeforeProfiling) {
                $sourceKey = json_encode([
                    $candidate['source_table'],
                    $candidate['source_column'],
                ], JSON_THROW_ON_ERROR);

                $candidate['relationship_ambiguity_count'] =
                    $ambiguityCounts[$sourceKey] ?? 1;
            }

            $ranked[] = [
                'candidate' => $candidate,
                'structural_confidence' => $this->scorer->scoreStructural(
                    $candidate,
                ),
                'original_index' => $originalIndex,
            ];
        }

        if ($rankBeforeProfiling) {
            usort(
                $ranked,
                static function (array $left, array $right): int {
                    $scoreComparison =
                        $right['structural_confidence']['evidence_score']
                        <=> $left['structural_confidence']['evidence_score'];

                    if ($scoreComparison !== 0) {
                        return $scoreComparison;
                    }

                    return $left['original_index'] <=> $right['original_index'];
                },
            );
        }

        $selected = $this->selectCandidatesToProfile(
            $ranked,
            $maxToProfile,
        );

        $profilingByOriginalIndex = [];

        if ($selected !== []) {
            $selectedCandidates = array_map(
                static fn (array $item): array => $item['candidate'],
                $selected,
            );

            $profiled = $this->profiler->profileCandidates(
                $selectedCandidates,
                $schema,
                $sampleLimit,
            );

            foreach ($selected as $selectedIndex => $item) {
                if (isset($profiled[$selectedIndex]['profiling'])) {
                    $profilingByOriginalIndex[$item['original_index']] =
                        $profiled[$selectedIndex]['profiling'];
                }
            }
        }

        $results = [];

        foreach ($ranked as $item) {
            $candidate = $item['candidate'];

            $profiling = $profilingByOriginalIndex[$item['original_index']]
                ?? [
                    'source_table' => $candidate['source_table'],
                    'source_column' => $candidate['source_column'],
                    'target_table' => $candidate['target_table'],
                    'target_column' => $candidate['target_column'],
                    'sampled_value_count' => 0,
                    'matched_value_count' => 0,
                    'match_rate' => null,
                    'status' => 'skipped_candidate_limit',
                ];


            $results[] = [
                ...$candidate,
                'structural_confidence' => $item['structural_confidence'],
                'confidence' => $this->scorer->score([
                    ...$candidate,
                    'profiling' => $profiling,
                ]),
                'profiling' => $profiling,
                'diagnostics' => $this->diagnostics->analyze($profiling),
            ];
        }

        return $results;
    }


    /**
     * Select candidates while prioritising reciprocal pairs and diverse
     * source columns and tables.
     *
     * @param list<array<string, mixed>> $ranked
     * @return list<array<string, mixed>>
     */
    private function selectCandidatesToProfile(
        array $ranked,
        int $limit,
    ): array {
        if ($limit === 0 || $ranked === []) {
            return [];
        }

        $selected = [];
        $selectedIndexes = [];
        $seenSourceColumns = [];
        $seenSourceTables = [];

        // First pass: select reciprocal pairs when both fit within the limit.
        foreach ($ranked as $index => $item) {
            $candidate = $item['candidate'];
            $originalIndex = $item['original_index'];

            if (isset($selectedIndexes[$originalIndex])) {
                continue;
            }

            foreach ($ranked as $otherIndex => $otherItem) {
                if ($otherIndex === $index) {
                    continue;
                }

                $other = $otherItem['candidate'];
                $otherOriginalIndex = $otherItem['original_index'];

                if (isset($selectedIndexes[$otherOriginalIndex])) {
                    continue;
                }

                $isReciprocal =
                    strcasecmp(
                        (string) $candidate['source_table'],
                        (string) $other['target_table'],
                ) === 0
                    && strcasecmp(
                        (string) $candidate['target_table'],
                        (string) $other['source_table'],
                ) === 0
                    && strcasecmp(
                        (string) $candidate['source_column'],
                        (string) $other['target_column'],
                ) === 0
                    && strcasecmp(
                        (string) $candidate['target_column'],
                        (string) $other['source_column'],
                ) === 0;

                if (!$isReciprocal) {
                    continue;
                }

                if (count($selected) + 2 > $limit) {
                    break 2;
                }

                foreach ([$item, $otherItem] as $pairItem) {
                    $pairCandidate = $pairItem['candidate'];
                    $pairOriginalIndex = $pairItem['original_index'];

                    $selected[] = $pairItem;
                    $selectedIndexes[$pairOriginalIndex] = true;

                    $seenSourceColumns[
                    strtolower((string) $pairCandidate['source_column'])
                    ] = true;

                    $seenSourceTables[
                    strtolower((string) $pairCandidate['source_table'])
                    ] = true;
                }

                break;
            }
        }

        if (count($selected) >= $limit) {
            return $selected;
        }

        // Second pass: prefer a new source column and a new source table.
        foreach ($ranked as $item) {
            $originalIndex = $item['original_index'];
            $candidate = $item['candidate'];
            $sourceColumn = strtolower((string) $candidate['source_column']);
            $sourceTable = strtolower((string) $candidate['source_table']);

            if (
                isset($selectedIndexes[$originalIndex])
                || isset($seenSourceColumns[$sourceColumn])
                || isset($seenSourceTables[$sourceTable])
            ) {
                continue;
            }

            $selected[] = $item;
            $selectedIndexes[$originalIndex] = true;
            $seenSourceColumns[$sourceColumn] = true;
            $seenSourceTables[$sourceTable] = true;

            if (count($selected) >= $limit) {
                return $selected;
            }
        }

        // Third pass: prioritise source columns not yet represented.
        foreach ($ranked as $item) {
            $originalIndex = $item['original_index'];
            $sourceColumn = strtolower(
                (string) $item['candidate']['source_column'],
        );

            if (
                isset($selectedIndexes[$originalIndex])
                || isset($seenSourceColumns[$sourceColumn])
            ) {
                continue;
            }

            $selected[] = $item;
            $selectedIndexes[$originalIndex] = true;
            $seenSourceColumns[$sourceColumn] = true;

            if (count($selected) >= $limit) {
                return $selected;
            }
        }

        // Fourth pass: prioritise source tables not yet represented.
        foreach ($ranked as $item) {
            $originalIndex = $item['original_index'];
            $sourceTable = strtolower(
                (string) $item['candidate']['source_table'],
        );

            if (
                isset($selectedIndexes[$originalIndex])
                || isset($seenSourceTables[$sourceTable])
            ) {
                continue;
            }

            $selected[] = $item;
            $selectedIndexes[$originalIndex] = true;
            $seenSourceTables[$sourceTable] = true;

            if (count($selected) >= $limit) {
                return $selected;
            }
        }

        // Final pass: fill remaining slots in structural ranking order.
        foreach ($ranked as $item) {
            $originalIndex = $item['original_index'];

            if (isset($selectedIndexes[$originalIndex])) {
                continue;
            }

            $selected[] = $item;
            $selectedIndexes[$originalIndex] = true;

            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }
}