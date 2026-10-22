<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use CzAiAgent\Database\Knowledge\SchemaFingerprint;
use PHPUnit\Framework\TestCase;

final class KnowledgeBaseLoaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir()
            . '/cz-ai-agent-loader-'
            . bin2hex(random_bytes(8))
            . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
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

        $store = new KnowledgeBaseStore($this->path);
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

        $store = new KnowledgeBaseStore($this->path);
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

        $store = new KnowledgeBaseStore($this->path);
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
            'format_version' => 1,
            'generated_at' => gmdate('c'),
            'source' => [
                'database' => $schema['database'],
                'schema_fingerprint' => $fingerprint->calculate($schema),
            ],
            'schema_summary' => [
                'table_count' => count($schema['tables']),
                'column_count' => count($schema['columns']),
                'declared_foreign_key_count' =>
                    count($schema['foreign_keys']),
            ],
            'profiling_config' => [
                'sample_limit' => 100,
                'max_declared_to_profile' => 5,
                'max_inferred_to_profile' => 20,
            ],
            'schema' => $schema,
            'relationships' => [
                'declared_relationships' => [],
                'inferred_candidates' => [],
            ],
        ];
    }
}
