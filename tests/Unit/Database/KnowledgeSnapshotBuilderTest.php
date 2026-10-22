<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\Discovery\SchemaDiscoveryAdapter;
use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use CzAiAgent\Database\Knowledge\KnowledgeSnapshotBuilder;
use CzAiAgent\Database\Profiling\DataProfilingAdapter;
use CzAiAgent\Database\Profiling\RelationshipProfilingService;
use CzAiAgent\Database\Profiling\SchemaRelationshipAnalyzer;
use CzAiAgent\Database\Profiling\SchemaRelationshipProfilingService;
use PHPUnit\Framework\TestCase;

final class KnowledgeSnapshotBuilderTest extends TestCase
{
    public function testReportsStructuralAndRelationshipProfilingCoverage(): void
    {
        $schema = [
            'database' => 'test_database',
            'tables' => [
                ['name' => 'parents', 'type' => 'BASE TABLE', 'comment' => ''],
                ['name' => 'children', 'type' => 'BASE TABLE', 'comment' => ''],
            ],
            'columns' => [
                [
                    'table_name' => 'parents',
                    'name' => 'id',
                    'data_type' => 'int',
                    'is_primary_key' => true,
                    'is_unique' => true,
                ],
                [
                    'table_name' => 'children',
                    'name' => 'parent_id',
                    'data_type' => 'int',
                    'is_primary_key' => false,
                    'is_unique' => false,
                ],
            ],
            'indexes' => [
                [
                    'table_name' => 'parents',
                    'name' => 'PRIMARY',
                    'is_unique' => true,
                    'type' => 'BTREE',
                    'columns' => [
                        [
                            'name' => 'id',
                            'ordinal_position' => 1,
                            'prefix_length' => null,
                        ],
                    ],
                ],
            ],
            'foreign_keys' => [
                [
                    'name' => 'fk_children_parent',
                    'ordinal_position' => 1,
                    'source_table' => 'children',
                    'source_column' => 'parent_id',
                    'target_schema' => 'test_database',
                    'target_table' => 'parents',
                    'target_column' => 'id',
                ],
            ],
            'foreign_key_constraints' => [
                [
                    'name' => 'fk_children_parent',
                    'source_table' => 'children',
                    'target_schema' => 'test_database',
                    'target_table' => 'parents',
                    'columns' => [
                        [
                            'source_column' => 'parent_id',
                            'target_column' => 'id',
                            'ordinal_position' => 1,
                        ],
                    ],
                ],
            ],
        ];
        $discovery = new SchemaDiscoveryService(
            new class($schema) implements SchemaDiscoveryAdapter {
                public function __construct(private readonly array $schema)
                {
                }

                public function discover(): array
                {
                    return $this->schema;
                }
            },
        );
        $profilingAdapter = new class implements DataProfilingAdapter {
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
                    'sampled_value_count' => 1,
                    'matched_value_count' => 1,
                    'match_rate' => 1.0,
                    'sample_limit' => $sampleLimit,
                    'sampling_method' => 'non_null_source_rows_limit',
                    'is_exhaustive' => true,
                    'status' => 'profiled',
                ];
            }
        };

        $builder = new KnowledgeSnapshotBuilder(
            $discovery,
            new SchemaRelationshipProfilingService(
                new SchemaRelationshipAnalyzer(),
                new RelationshipProfilingService($profilingAdapter),
            ),
        );

        $snapshot = $builder->build(
            sampleLimit: 25,
            maxInferredToProfile: 0,
        );

        self::assertSame(3, $snapshot['format_version']);
        self::assertSame(1, $snapshot['schema_summary']['index_count']);
        self::assertSame(
            1,
            $snapshot['coverage']['declared_constraints_total'],
        );
        self::assertSame(
            1,
            $snapshot['coverage']['declared_constraints_profiled'],
        );
        self::assertTrue($snapshot['coverage']['structural_catalog_complete']);
        self::assertTrue($snapshot['coverage']['build_complete']);
        self::assertSame(25, $snapshot['profiling_config']['sample_limit']);
    }
}
