<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\Profiling\DataProfilingAdapter;
use CzAiAgent\Database\Profiling\RelationshipProfilingService;
use CzAiAgent\Database\Profiling\SchemaRelationshipAnalyzer;
use CzAiAgent\Database\Profiling\SchemaRelationshipProfilingService;
use PHPUnit\Framework\TestCase;

final class SchemaRelationshipProfilingServiceTest extends TestCase
{
    public function testAnalysesAndProfilesInferredRelationships(): void
    {
        $schema = [
            'database' => 'test_database',
            'tables' => [
                ['name' => 'clients', 'type' => 'BASE TABLE', 'comment' => ''],
                ['name' => 'claims', 'type' => 'BASE TABLE', 'comment' => ''],
            ],
            'columns' => [
                [
                    'table_name' => 'clients',
                    'name' => 'id',
                    'data_type' => 'int',
                    'is_primary_key' => true,
                    'is_unique' => true,
                ],
                [
                    'table_name' => 'claims',
                    'name' => 'client_id',
                    'data_type' => 'int',
                    'is_primary_key' => false,
                    'is_unique' => false,
                ],
            ],
            'foreign_keys' => [],
        ];

        $adapter = new class implements DataProfilingAdapter {
            public function profileRelationship(
                array $candidate,
                array $schema,
                int $sampleLimit = 500,
            ): array {
                return [
                    'source_table' => $candidate['source_table'],
                    'source_column' => $candidate['source_column'],
                    'target_table' => $candidate['target_table'],
                    'target_column' => $candidate['target_column'],
                    'sampled_value_count' => 10,
                    'matched_value_count' => 10,
                    'match_rate' => 1.0,
                    'status' => 'profiled',
                ];
            }
        };

        $service = new SchemaRelationshipProfilingService(
            new SchemaRelationshipAnalyzer(),
            new RelationshipProfilingService($adapter),
        );

        $result = $service->analyzeAndProfile($schema, 10);

        self::assertSame([], $result['declared_relationships']);
        self::assertNotEmpty($result['inferred_candidates']);

        $candidate = $result['inferred_candidates'][0];

        self::assertSame('claims', $candidate['source_table']);
        self::assertSame('client_id', $candidate['source_column']);
        self::assertSame('clients', $candidate['target_table']);
        self::assertSame('id', $candidate['target_column']);
        self::assertSame('inferred_unverified', $candidate['status']);
        self::assertSame(1.0, $candidate['profiling']['match_rate']);
    }

    public function testCandidateLimitSkipsProfilingButPreservesCandidate(): void
    {
        $schema = [
            'database' => 'test_database',
            'tables' => [
                ['name' => 'clients', 'type' => 'BASE TABLE', 'comment' => ''],
                ['name' => 'claims', 'type' => 'BASE TABLE', 'comment' => ''],
            ],
            'columns' => [
                [
                    'table_name' => 'clients',
                    'name' => 'id',
                    'data_type' => 'int',
                    'is_primary_key' => true,
                    'is_unique' => true,
                ],
                [
                    'table_name' => 'claims',
                    'name' => 'client_id',
                    'data_type' => 'int',
                    'is_primary_key' => false,
                    'is_unique' => false,
                ],
            ],
            'foreign_keys' => [],
        ];

        $adapter = new class implements DataProfilingAdapter {
            public int $calls = 0;

            public function profileRelationship(
                array $candidate,
                array $schema,
                int $sampleLimit = 500,
        ): array {
                $this->calls++;

                return [
                    'source_table' => $candidate['source_table'],
                    'source_column' => $candidate['source_column'],
                    'target_table' => $candidate['target_table'],
                    'target_column' => $candidate['target_column'],
                    'sampled_value_count' => 10,
                    'matched_value_count' => 10,
                    'match_rate' => 1.0,
                    'status' => 'profiled',
                ];
            }
        };

        $service = new SchemaRelationshipProfilingService(
            new SchemaRelationshipAnalyzer(),
            new RelationshipProfilingService($adapter),
    );

        $result = $service->analyzeAndProfile(
            $schema,
            sampleLimit: 10,
            maxDeclaredToProfile: 20,
            maxInferredToProfile: 0,
    );

        self::assertNotEmpty($result['inferred_candidates']);
        self::assertSame(0, $adapter->calls);
        self::assertSame(
            'skipped_candidate_limit',
            $result['inferred_candidates'][0]['profiling']['status'],
    );
        self::assertNull(
            $result['inferred_candidates'][0]['profiling']['match_rate'],
    );
        self::assertArrayHasKey(
            'evidence_score',
            $result['inferred_candidates'][0]['confidence'],
    );
    }

}
