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
        int $maxDeclaredToProfile = 5,
        int $maxInferredToProfile = 20,
    ): array {
        $schema = $this->discovery->discover();

        $relationships = $this->relationshipProfiler->analyzeAndProfile(
            $schema,
            sampleLimit: $sampleLimit,
            maxDeclaredToProfile: $maxDeclaredToProfile,
            maxInferredToProfile: $maxInferredToProfile,
        );

        return [
            'format_version' => 2,
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
                'declared_foreign_key_count' => count(
                    $schema['foreign_keys'],
                ),
            ],
            'profiling_config' => [
                'sample_limit' => $sampleLimit,
                'max_declared_to_profile' => $maxDeclaredToProfile,
                'max_inferred_to_profile' => $maxInferredToProfile,
            ],
            'schema' => $schema,
            'relationships' => $relationships,
        ];
    }
}
