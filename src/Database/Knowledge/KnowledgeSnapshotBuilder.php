<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Knowledge;

use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use CzAiAgent\Database\Profiling\SchemaRelationshipProfilingService;

final class KnowledgeSnapshotBuilder
{
    public function __construct(
        private readonly SchemaDiscoveryService $discovery,
        private readonly SchemaRelationshipProfilingService $relationshipProfiler,
        private readonly SchemaFingerprint $fingerprint = new SchemaFingerprint(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        int $sampleLimit = 100,
        int $maxInferredToProfile = 20,
    ): array {
        $schema = $this->discovery->discover();

        $relationships = $this->relationshipProfiler->analyzeAndProfile(
            $schema,
            sampleLimit: $sampleLimit,
            maxInferredToProfile: $maxInferredToProfile,
        );

        $declaredStatusCounts = $this->profilingStatusCounts(
            $relationships['declared_relationships'],
        );
        $inferredStatusCounts = $this->profilingStatusCounts(
            $relationships['inferred_candidates'],
        );
        $foreignKeyConstraintCount = count(
            $schema['foreign_key_constraints'] ?? $this->groupForeignKeys(
                $schema['foreign_keys'],
            ),
        );
        $declaredProfileOutcomes = array_sum($declaredStatusCounts);
        $structuralCatalogComplete = isset($schema['indexes'])
            && isset($schema['foreign_key_constraints']);

        return [
            'format_version' => 3,
            'generated_at' => gmdate('c'),
            'source' => [
                'database' => $schema['database'],
                'schema_fingerprint' => $this->fingerprint->calculate(
                    $schema,
                ),
            ],
            'schema_summary' => [
                'table_count' => count($schema['tables']),
                'column_count' => count($schema['columns']),
                'index_count' => count($schema['indexes'] ?? []),
                'declared_foreign_key_count' => $foreignKeyConstraintCount,
                'foreign_key_column_pair_count' => count($schema['foreign_keys']),
            ],
            'profiling_config' => [
                'sample_limit' => $sampleLimit,
                'declared_relationship_mode' => 'all_declared_constraints',
                'max_inferred_to_profile' => $maxInferredToProfile,
            ],
            'coverage' => [
                'structural_catalog_complete' => $structuralCatalogComplete,
                'declared_constraints_total' => $foreignKeyConstraintCount,
                'declared_constraints_with_profile_outcome' =>
                    $declaredProfileOutcomes,
                'declared_constraints_profiled' =>
                    $declaredStatusCounts['profiled'] ?? 0,
                'declared_profile_status_counts' => $declaredStatusCounts,
                'inferred_candidates_total' =>
                    count($relationships['inferred_candidates']),
                'inferred_candidates_profiled' =>
                    $inferredStatusCounts['profiled'] ?? 0,
                'inferred_profile_status_counts' => $inferredStatusCounts,
                'build_complete' => $structuralCatalogComplete
                    && $declaredProfileOutcomes === $foreignKeyConstraintCount,
                'max_inferred_to_profile' => $maxInferredToProfile,
            ],
            'schema' => $schema,
            'relationships' => $relationships,
        ];
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @return array<string, int>
     */
    private function profilingStatusCounts(array $relationships): array
    {
        $counts = [];

        foreach ($relationships as $relationship) {
            $status = (string) ($relationship['profiling']['status'] ?? 'missing');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $foreignKeys
     * @return list<string>
     */
    private function groupForeignKeys(array $foreignKeys): array
    {
        $groups = [];

        foreach ($foreignKeys as $index => $foreignKey) {
            $key = json_encode([
                $foreignKey['source_table'] ?? null,
                $foreignKey['name'] ?? "unnamed-{$index}",
                $foreignKey['target_schema'] ?? null,
                $foreignKey['target_table'] ?? null,
            ], JSON_THROW_ON_ERROR);
            $groups[$key] = true;
        }

        return array_keys($groups);
    }
}
