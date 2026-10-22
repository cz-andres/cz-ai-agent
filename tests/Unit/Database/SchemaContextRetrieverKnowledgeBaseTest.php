<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use CzAiAgent\Database\Context\SchemaContextRetriever;
use PHPUnit\Framework\TestCase;

final class SchemaContextRetrieverKnowledgeBaseTest extends TestCase
{
    private string $libraryPath;

    protected function setUp(): void
    {
        $this->libraryPath = sys_get_temp_dir()
            . '/cz-ai-knowledge-'
            . bin2hex(random_bytes(8));

        (new KnowledgeBaseStore($this->libraryPath))->save(
            $this->snapshot()
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->libraryPath)) {
            $this->removeDirectory($this->libraryPath);
        }
    }

    public function testItRetrievesRelevantInferredRelationshipsFromSnapshot(): void
    {
        $loader = new KnowledgeBaseLoader(
            new KnowledgeBaseStore($this->libraryPath)
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
            'indexes' => [],
            'foreign_keys' => [],
            'foreign_key_constraints' => [],
        ];

        return [
            'format_version' => 3,
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'source' => [
                'database' => 'test_database',
                'schema_fingerprint' => str_repeat('a', 64),
            ],
            'schema_summary' => [
                'table_count' => 2,
                'column_count' => 2,
                'index_count' => 0,
                'declared_foreign_key_count' => 0,
                'foreign_key_column_pair_count' => 0,
            ],
            'profiling_config' => [
                'sample_limit' => 100,
                'declared_relationship_mode' => 'all_declared_constraints',
                'max_inferred_to_profile' => 20,
            ],
            'coverage' => [
                'structural_catalog_complete' => true,
                'declared_constraints_total' => 0,
                'declared_constraints_with_profile_outcome' => 0,
                'declared_constraints_profiled' => 0,
                'declared_profile_status_counts' => [],
                'inferred_candidates_total' => 1,
                'inferred_candidates_profiled' => 0,
                'inferred_profile_status_counts' => [
                    'skipped_candidate_limit' => 1,
                ],
                'build_complete' => true,
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

    private function removeDirectory(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $entryPath = $entry->getPathname();

            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }

        rmdir($directory);
    }
}