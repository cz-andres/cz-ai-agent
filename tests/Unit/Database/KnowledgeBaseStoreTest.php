<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use JsonException;
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
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $this->removeDirectory($this->directory);
        }
    }

    public function testSavesAndLoadsKnowledgeLibrary(): void
    {
        $store = new KnowledgeBaseStore($this->directory);
        $snapshot = $this->validSnapshot();
        $snapshot['relationships']['declared_relationships'] = [
            [
                'constraint_name' => 'fk_source_target',
                'source_table' => 'source_records',
                'source_column' => 'target_id',
                'target_schema' => 'test_database',
                'target_table' => 'target_records',
                'target_column' => 'id',
                'status' => 'declared_foreign_key',
                'confidence' => [
                    'assessment_status' => 'declared_foreign_key',
                    'data_profiled' => true,
                ],
                'profiling' => [
                    'status' => 'profiled',
                    'sampled_value_count' => 10,
                    'matched_value_count' => 10,
                    'match_rate' => 1.0,
                ],
                'diagnostics' => ['classification' => 'consistent'],
            ],
        ];

        $store->save($snapshot);

        self::assertSame($snapshot, $store->load());
        self::assertFileExists($this->directory . '/current.json');
        self::assertFileExists(
            $this->directory . '/builds/'
            . $this->currentBuildId()
            . '/catalog/tables.json',
        );
        self::assertFileExists(
            $this->directory . '/builds/'
            . $this->currentBuildId()
            . '/catalog/indexes.json',
        );
        self::assertFileExists(
            $this->directory . '/builds/'
            . $this->currentBuildId()
            . '/relationships/declared.json',
        );
        self::assertFileExists(
            $this->directory . '/builds/'
            . $this->currentBuildId()
            . '/profiling/declared.json',
        );
        $relationshipData = file_get_contents(
            $this->directory . '/builds/' . $this->currentBuildId()
            . '/relationships/declared.json',
        );
        $profilingData = file_get_contents(
            $this->directory . '/builds/' . $this->currentBuildId()
            . '/profiling/declared.json',
        );
        self::assertIsString($relationshipData);
        self::assertIsString($profilingData);
        self::assertStringNotContainsString('profiling', $relationshipData);
        self::assertStringContainsString('match_rate', $profilingData);
        $manifest = json_decode(
            (string) file_get_contents(
                $this->directory . '/builds/' . $this->currentBuildId()
                . '/manifest.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($snapshot['coverage'], $manifest['coverage']);
    }

    public function testRejectsUnsupportedVersion(): void
    {
        $store = new KnowledgeBaseStore($this->directory);
        $snapshot = $this->validSnapshot();
        $snapshot['format_version'] = 99;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Unsupported knowledge library format version.',
        );

        $store->save($snapshot);
    }

    public function testRejectsMissingRequiredFields(): void
    {
        $store = new KnowledgeBaseStore($this->directory);
        $snapshot = $this->validSnapshot();
        unset($snapshot['relationships']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge library is missing required field: relationships',
        );

        $store->save($snapshot);
    }

    public function testRejectsMalformedJson(): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents($this->directory . '/current.json', '{invalid');

        $store = new KnowledgeBaseStore($this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge active knowledge pointer contains invalid JSON.',
        );

        $store->load();
    }

    public function testRejectsMissingActiveLibraryBuild(): void
    {
        $store = new KnowledgeBaseStore($this->directory . '/missing');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge library has no active build.',
        );

        $store->load();
    }

    public function testDoesNotTreatLegacySnapshotAsActiveBuild(): void
    {
        mkdir($this->directory, 0775, true);
        file_put_contents(
            $this->directory . '/snapshot.json',
            json_encode($this->validSnapshot(), JSON_THROW_ON_ERROR),
        );

        $store = new KnowledgeBaseStore($this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge library has no active build.',
        );

        $store->load();
    }

    public function testRejectsChangedArtifact(): void
    {
        $store = new KnowledgeBaseStore($this->directory);
        $store->save($this->validSnapshot());

        $tablesPath = $this->directory . '/builds/'
            . $this->currentBuildId()
            . '/catalog/tables.json';
        file_put_contents($tablesPath, '[]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Knowledge artifact checksum does not match: catalog/tables.json',
        );

        $store->load();
    }

    public function testFailedBuildDoesNotReplaceActiveBuild(): void
    {
        $store = new KnowledgeBaseStore($this->directory);
        $store->save($this->validSnapshot());
        $activePointerBefore = file_get_contents(
            $this->directory . '/current.json',
        );

        $invalidSnapshot = $this->validSnapshot();
        $invalidSnapshot['schema']['tables'][] = [
            'name' => "invalid-\xB1",
            'type' => 'BASE TABLE',
            'comment' => '',
        ];

        try {
            $store->save($invalidSnapshot);
            self::fail('Expected invalid UTF-8 to abort the build.');
        } catch (JsonException) {
            self::assertSame(
                $activePointerBefore,
                file_get_contents($this->directory . '/current.json'),
            );
            self::assertSame([], $store->load()['schema']['tables']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validSnapshot(): array
    {
        return [
            'format_version' => 3,
            'generated_at' => '2026-10-21T00:00:00+00:00',
            'source' => [
                'database' => 'test_database',
                'schema_fingerprint' => str_repeat('a', 64),
            ],
            'schema_summary' => [
                'table_count' => 0,
                'column_count' => 0,
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
                'inferred_candidates_total' => 0,
                'inferred_candidates_profiled' => 0,
                'inferred_profile_status_counts' => [],
                'build_complete' => true,
                'max_inferred_to_profile' => 20,
            ],
            'schema' => [
                'database' => 'test_database',
                'tables' => [],
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => [],
                'foreign_key_constraints' => [],
            ],
            'relationships' => [
                'declared_relationships' => [],
                'inferred_candidates' => [],
            ],
        ];
    }

    private function currentBuildId(): string
    {
        $pointer = json_decode(
            (string) file_get_contents($this->directory . '/current.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $pointer['build_id'];
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
