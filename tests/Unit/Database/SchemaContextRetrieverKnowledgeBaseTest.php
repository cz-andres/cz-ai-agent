<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use CzAiAgent\Database\Context\SchemaContextRetriever;
use PHPUnit\Framework\TestCase;

final class SchemaContextRetrieverKnowledgeBaseTest extends TestCase
{
    private string $snapshotPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cz-ai-knowledge-');

        if ($path === false) {
            self::fail('Could not create temporary snapshot path.');
        }

        $this->snapshotPath = $path;

        (new KnowledgeBaseStore($this->snapshotPath))->save(
            $this->snapshot()
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->snapshotPath)) {
            unlink($this->snapshotPath);
        }
    }

    public function testItRetrievesRelevantInferredRelationshipsFromSnapshot(): void
    {
        $loader = new KnowledgeBaseLoader(
            new KnowledgeBaseStore($this->snapshotPath)
        );

        $retriever = new SchemaContextRetriever(
            knowledgeBaseLoader: $loader,
        );

        $context = $retriever->retrieve('source target records');

        self::assertSame('test_database', $context['database']);
        self::assertCount(2, $context['tables']);
        self::assertSame([], $context['relationships']);
        self::assertCount(
            1,
            $context['inferred_relationship_candidates']
        );
        self::assertSame(
            'inferred_unverified',
            $context['inferred_relationship_candidates'][0]['status']
        );
        self::assertSame(
            'source_records',
            $context['inferred_relationship_candidates'][0]['source_table']
        );
        self::assertSame(
            'target_records',
            $context['inferred_relationship_candidates'][0]['target_table']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $schema = [
            'database' => 'test_database',
            'tables' => [
                [
                    'name' => 'source_records',
                    'type' => 'BASE TABLE',
                    'comment' => '',
                ],
                [
                    'name' => 'target_records',
                    'type' => 'BASE TABLE',
                    'comment' => '',
                ],
            ],
            'columns' => [
                [
                    'table_name' => 'source_records',
                    'name' => 'target_id',
                    'data_type' => 'int',
                    'comment' => '',
                    'is_primary_key' => false,
                    'is_indexed' => false,
                ],
                [
                    'table_name' => 'target_records',
                    'name' => 'id',
                    'data_type' => 'int',
                    'comment' => '',
                    'is_primary_key' => true,
                    'is_indexed' => true,
                ],
            ],
            'foreign_keys' => [],
        ];

        return [
            'format_version' => 1,
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'source' => [
                'database' => 'test_database',
                'schema_fingerprint' => str_repeat('a', 64),
            ],
            'schema_summary' => [
                'table_count' => 2,
                'column_count' => 2,
                'declared_foreign_key_count' => 0,
            ],
            'profiling_config' => [
                'sample_limit' => 100,
                'max_declared_to_profile' => 5,
                'max_inferred_to_profile' => 20,
            ],
            'schema' => $schema,
            'relationships' => [
                'declared_relationships' => [],
                'inferred_candidates' => [
                    [
                        'source_table' => 'source_records',
                        'source_column' => 'target_id',
                        'target_table' => 'target_records',
                        'target_column' => 'id',
                        'status' => 'inferred_unverified',
                        'confidence' => [
                            'evidence_score' => 0.85,
                            'evidence_strength' => 'high',
                            'data_profiled' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}