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
    public function testProfilesEveryDeclaredConstraintIncludingCompositeOnes(): void
    {
        $schema = [
            'database' => 'test_database',
            'tables' => [
                ['name' => 'accounts', 'type' => 'BASE TABLE', 'comment' => ''],
                ['name' => 'account_links', 'type' => 'BASE TABLE', 'comment' => ''],
                ['name' => 'audit_records', 'type' => 'BASE TABLE', 'comment' => ''],
            ],
            'columns' => [
                [
                    'table_name' => 'accounts',
                    'name' => 'tenant_id',
                    'data_type' => 'int',
                    'is_primary_key' => true,
                    'is_unique' => false,
                ],
                [
                    'table_name' => 'accounts',
                    'name' => 'account_id',
                    'data_type' => 'int',
                    'is_primary_key' => true,
                    'is_unique' => false,
                ],
                [
                    'table_name' => 'account_links',
                    'name' => 'tenant_id',
                    'data_type' => 'int',
                    'is_primary_key' => false,
                    'is_unique' => false,
                ],
                [
                    'table_name' => 'account_links',
                    'name' => 'account_id',
                    'data_type' => 'int',
                    'is_primary_key' => false,
                    'is_unique' => false,
                ],
                [
                    'table_name' => 'audit_records',
                    'name' => 'account_id',
                    'data_type' => 'int',
                    'is_primary_key' => false,
                    'is_unique' => false,
                ],
            ],
            'foreign_keys' => [
                [
                    'name' => 'fk_account_links_accounts',
                    'ordinal_position' => 1,
                    'source_table' => 'account_links',
                    'source_column' => 'tenant_id',
                    'target_schema' => 'test_database',
                    'target_table' => 'accounts',
                    'target_column' => 'tenant_id',
                ],
                [
                    'name' => 'fk_account_links_accounts',
                    'ordinal_position' => 2,
                    'source_table' => 'account_links',
                    'source_column' => 'account_id',
                    'target_schema' => 'test_database',
                    'target_table' => 'accounts',
                    'target_column' => 'account_id',
                ],
            ],
        ];

        $adapter = new class implements DataProfilingAdapter {
            public array $profiledConstraints = [];

            public function profileRelationship(
                array $candidate,
                array $schema,
                int $sampleLimit = 500,
            ): array {
                $this->profiledConstraints[] = $candidate;

                return [
                    'source_table' => $candidate['source_table'],
                    'source_column' => $candidate['source_column'],
                    'target_table' => $candidate['target_table'],
                    'target_column' => $candidate['target_column'],
                    'sampled_value_count' => 12,
                    'matched_value_count' => 12,
                    'match_rate' => 1.0,
                    'status' => 'profiled',
                ];
            }
        };

        $result = (new SchemaRelationshipProfilingService(
            new SchemaRelationshipAnalyzer(),
            new RelationshipProfilingService($adapter),
        ))->analyzeAndProfile(
            $schema,
            sampleLimit: 20,
            maxInferredToProfile: 0,
        );

        self::assertCount(1, $adapter->profiledConstraints);
        self::assertCount(
            2,
            $adapter->profiledConstraints[0]['column_pairs'],
        );
        self::assertSame(
            'profiled',
            $result['declared_relationships'][0]['profiling']['status'],
        );
        self::assertSame([], $result['inferred_candidates']);
    }

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
