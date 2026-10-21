<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\KnowledgeBaseStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KnowledgeBaseStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/cz-ai-agent-knowledge-'
            . bin2hex(random_bytes(8));

        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (glob($this->directory . '/.*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testSavesAndLoadsSnapshot(): void
    {
        $store = new KnowledgeBaseStore(
            $this->directory . '/snapshot.json',
        );

        $snapshot = $this->validSnapshot();

        $store->save($snapshot);

        self::assertSame($snapshot, $store->load());
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $store = new KnowledgeBaseStore(
            $this->directory . '/snapshot.json',
        );

        $snapshot = $this->validSnapshot();
        $snapshot['format_version'] = 99;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Unsupported knowledge snapshot format version.',
        );

        $store->save($snapshot);
    }

    public function testRejectsMissingRequiredFields(): void
    {
        $store = new KnowledgeBaseStore(
            $this->directory . '/snapshot.json',
        );

        $snapshot = $this->validSnapshot();
        unset($snapshot['relationships']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge snapshot is missing required field: relationships',
        );

        $store->save($snapshot);
    }

    public function testRejectsMalformedJson(): void
    {
        $path = $this->directory . '/snapshot.json';
        file_put_contents($path, '{invalid');

        $store = new KnowledgeBaseStore($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge snapshot contains invalid JSON.',
        );

        $store->load();
    }

    public function testRejectsMissingSnapshot(): void
    {
        $store = new KnowledgeBaseStore(
            $this->directory . '/missing.json',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge snapshot does not exist.',
        );

        $store->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function validSnapshot(): array
    {
        return [
            'format_version' => 1,
            'generated_at' => '2026-10-21T00:00:00+00:00',
            'source' => [
                'database' => 'test_database',
                'schema_fingerprint' => str_repeat('a', 64),
            ],
            'schema_summary' => [
                'table_count' => 0,
                'column_count' => 0,
                'declared_foreign_key_count' => 0,
            ],
            'profiling_config' => [
                'sample_limit' => 100,
                'max_declared_to_profile' => 5,
                'max_inferred_to_profile' => 20,
            ],
            'schema' => [
                'database' => 'test_database',
                'tables' => [],
                'columns' => [],
                'foreign_keys' => [],
            ],
            'relationships' => [
                'declared_relationships' => [],
                'inferred_candidates' => [],
            ],
        ];
    }
}
