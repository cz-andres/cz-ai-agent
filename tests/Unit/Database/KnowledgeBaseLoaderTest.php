<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use CzAiAgent\Database\Knowledge\SchemaFingerprint;
use PHPUnit\Framework\TestCase;

final class KnowledgeBaseLoaderTest extends TestCase
{
    private string $libraryPath;

    protected function setUp(): void
    {
        $this->libraryPath = sys_get_temp_dir()
            . '/cz-ai-agent-loader-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->libraryPath)) {
            $this->removeDirectory($this->libraryPath);
        }
    }

    public function testReportsCurrentSchema(): void
    {
        $schema = [
            'database' => 'test_database',
            'tables' => [['name' => 'table_a']],
            'columns' => [],
            'foreign_keys' => [],
        ];

        $store = new KnowledgeBaseStore($this->libraryPath);
        $fingerprint = new SchemaFingerprint();
        $snapshot = $this->snapshot($schema, $fingerprint);
        $store->save($snapshot);

        $loader = new KnowledgeBaseLoader($store, $fingerprint);

        self::assertSame(
            'current',
            $loader->load($schema)['schema_status'],
        );
    }

    public function testReportsStaleSchema(): void
    {
        $original = [
            'database' => 'test_database',
            'tables' => [['name' => 'table_a']],
            'columns' => [],
            'foreign_keys' => [],
        ];

        $changed = $original;
        $changed['tables'][] = ['name' => 'table_b'];

        $store = new KnowledgeBaseStore($this->libraryPath);
        $fingerprint = new SchemaFingerprint();
        $store->save($this->snapshot($original, $fingerprint));

        $loader = new KnowledgeBaseLoader($store, $fingerprint);

        self::assertSame(
            'stale',
            $loader->load($changed)['schema_status'],
        );
    }

    public function testCanLoadWithoutCheckingFreshness(): void
    {
        $schema = [
            'database' => 'test_database',
            'tables' => [],
            'columns' => [],
            'foreign_keys' => [],
        ];

        $store = new KnowledgeBaseStore($this->libraryPath);
        $fingerprint = new SchemaFingerprint();
        $store->save($this->snapshot($schema, $fingerprint));

        $loader = new KnowledgeBaseLoader($store, $fingerprint);

        self::assertSame(
            'not_checked',
            $loader->load()['schema_status'],
        );
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function snapshot(
        array $schema,
        SchemaFingerprint $fingerprint,
    ): array {
        return [
            'format_version' => 3,
            'generated_at' => gmdate('c'),
            'source' => [
                'database' => $schema['database'],
                'schema_fingerprint' => $fingerprint->calculate($schema),
            ],
            'schema_summary' => [
                'table_count' => count($schema['tables']),
                'column_count' => count($schema['columns']),
                'index_count' => 0,
                'declared_foreign_key_count' =>
                    count($schema['foreign_keys']),
                'foreign_key_column_pair_count' =>
                    count($schema['foreign_keys']),
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
                'inferred_candidates_total' => 0,
                'inferred_candidates_profiled' => 0,
                'inferred_profile_status_counts' => [],
                'build_complete' => true,
                'max_inferred_to_profile' => 20,
            ],
            'schema' => array_replace($schema, [
                'indexes' => [],
                'foreign_key_constraints' => [],
            ]),
            'relationships' => [
                'declared_relationships' => [],
                'inferred_candidates' => [],
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
