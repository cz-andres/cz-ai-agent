<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Knowledge;

use JsonException;
use RuntimeException;

final class KnowledgeBaseStore
{
    private const FORMAT_VERSION = 2;

    /**
     * @var array<string, string>
     */
    private const ARTIFACT_PATHS = [
        'tables' => 'catalog/tables.json',
        'columns' => 'catalog/columns.json',
        'foreign_keys' => 'catalog/foreign-keys.json',
        'declared_relationships' => 'relationships/declared.json',
        'inferred_candidates' => 'relationships/inferred.json',
        'declared_profiling' => 'profiling/declared.json',
        'inferred_profiling' => 'profiling/inferred.json',
    ];

    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(array $snapshot): string
    {
        $this->validate($snapshot);

        $buildId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $buildsDirectory = $this->path . '/builds';
        $stagingDirectory = $buildsDirectory . '/.building-' . $buildId;
        $buildDirectory = $buildsDirectory . '/' . $buildId;

        if (
            !is_dir($buildsDirectory)
            && !mkdir($buildsDirectory, 0775, true)
            && !is_dir($buildsDirectory)
        ) {
            throw new RuntimeException(
                'Unable to create knowledge directory.',
            );
        }

        try {
            if (!mkdir($stagingDirectory, 0775, true)) {
                throw new RuntimeException(
                    'Unable to create a staging directory for the knowledge build.',
                );
            }

            $artifactContents = $this->artifacts($snapshot);
            $checksums = [];

            foreach ($artifactContents as $name => $contents) {
                $relativePath = self::ARTIFACT_PATHS[$name];
                $artifactPath = $stagingDirectory . '/' . $relativePath;
                $artifactDirectory = dirname($artifactPath);

                if (
                    !is_dir($artifactDirectory)
                    && !mkdir($artifactDirectory, 0775, true)
                    && !is_dir($artifactDirectory)
                ) {
                    throw new RuntimeException(
                        'Unable to create a knowledge artifact directory.',
                    );
                }

                $json = $this->encode($contents);
                $this->writeFile($artifactPath, $json);
                $checksums[$relativePath] = hash('sha256', $json);
            }

            $manifest = [
                'format_version' => self::FORMAT_VERSION,
                'build_id' => $buildId,
                'generated_at' => $snapshot['generated_at'],
                'source' => $snapshot['source'],
                'schema_summary' => $snapshot['schema_summary'],
                'profiling_config' => $snapshot['profiling_config'],
                'artifacts' => $checksums,
            ];
            $manifestJson = $this->encode($manifest);
            $this->writeFile(
                $stagingDirectory . '/manifest.json',
                $manifestJson,
            );

            if (!rename($stagingDirectory, $buildDirectory)) {
                throw new RuntimeException(
                    'Unable to publish the completed knowledge build.',
                );
            }

            $current = [
                'format_version' => self::FORMAT_VERSION,
                'build_id' => $buildId,
                'manifest_sha256' => hash('sha256', $manifestJson),
            ];

            $this->writeCurrentPointer($current);

            return $buildId;
        } finally {
            if (is_dir($stagingDirectory)) {
                $this->removeDirectory($stagingDirectory);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $currentPath = $this->path . '/current.json';

        if (!is_file($currentPath)) {
            throw new RuntimeException(
                'Knowledge library has no active build.',
            );
        }

        $current = $this->readJson($currentPath, 'active knowledge pointer');

        if (
            ($current['format_version'] ?? null) !== self::FORMAT_VERSION
            || !is_string($current['build_id'] ?? null)
            || !preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $current['build_id'])
            || !is_string($current['manifest_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/', $current['manifest_sha256'])
        ) {
            throw new RuntimeException(
                'Active knowledge pointer has an invalid structure.',
            );
        }

        $buildDirectory = $this->path . '/builds/' . $current['build_id'];
        $manifestPath = $buildDirectory . '/manifest.json';
        $manifestJson = $this->readFile($manifestPath);

        if (!hash_equals(
            $current['manifest_sha256'],
            hash('sha256', $manifestJson),
        )) {
            throw new RuntimeException(
                'Knowledge library manifest checksum does not match.',
            );
        }

        $manifest = $this->decode($manifestJson, 'knowledge manifest');
        $this->validateManifest($manifest, $current['build_id']);

        $artifacts = [];

        foreach (self::ARTIFACT_PATHS as $name => $relativePath) {
            $json = $this->readFile($buildDirectory . '/' . $relativePath);
            $expectedChecksum = $manifest['artifacts'][$relativePath];

            if (!hash_equals($expectedChecksum, hash('sha256', $json))) {
                throw new RuntimeException(
                    "Knowledge artifact checksum does not match: {$relativePath}",
                );
            }

            $artifacts[$name] = $this->decode(
                $json,
                "knowledge artifact {$relativePath}",
            );
        }

        $snapshot = [
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => $manifest['generated_at'],
            'source' => $manifest['source'],
            'schema_summary' => $manifest['schema_summary'],
            'profiling_config' => $manifest['profiling_config'],
            'schema' => [
                'database' => $manifest['source']['database'],
                'tables' => $artifacts['tables'],
                'columns' => $artifacts['columns'],
                'foreign_keys' => $artifacts['foreign_keys'],
            ],
            'relationships' => [
                'declared_relationships' => $this->mergeProfiling(
                    $artifacts['declared_relationships'],
                    $artifacts['declared_profiling'],
                    true,
                ),
                'inferred_candidates' => $this->mergeProfiling(
                    $artifacts['inferred_candidates'],
                    $artifacts['inferred_profiling'],
                    false,
                ),
            ],
        ];

        $this->validate($snapshot);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function validate(array $snapshot): void
    {
        if (($snapshot['format_version'] ?? null) !== self::FORMAT_VERSION) {
            throw new RuntimeException(
                'Unsupported knowledge library format version.',
            );
        }

        foreach ([
                     'generated_at',
                     'source',
                     'schema_summary',
                     'profiling_config',
                     'schema',
                     'relationships',
                 ] as $key) {
            if (!array_key_exists($key, $snapshot)) {
                throw new RuntimeException(
                    "Knowledge library is missing required field: {$key}",
                );
            }
        }

        if (
            !is_array($snapshot['source'])
            || !is_string($snapshot['source']['database'] ?? null)
            || !is_string(
                $snapshot['source']['schema_fingerprint'] ?? null,
            )
            || !preg_match(
                '/^[a-f0-9]{64}$/',
                $snapshot['source']['schema_fingerprint'],
            )
            || !is_array($snapshot['schema'])
            || !is_array($snapshot['schema']['tables'] ?? null)
            || !is_array($snapshot['schema']['columns'] ?? null)
            || !is_array($snapshot['schema']['foreign_keys'] ?? null)
            || !is_array($snapshot['relationships'])
            || !is_array(
                $snapshot['relationships']['declared_relationships']
                ?? null,
            )
            || !is_array(
                $snapshot['relationships']['inferred_candidates'] ?? null,
            )
        ) {
            throw new RuntimeException(
                'Knowledge library has an invalid structure.',
            );
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, array<mixed>>
     */
    private function artifacts(array $snapshot): array
    {
        return [
            'tables' => $snapshot['schema']['tables'],
            'columns' => $snapshot['schema']['columns'],
            'foreign_keys' => $snapshot['schema']['foreign_keys'],
            'declared_relationships' => $this->stripProfiling(
                $snapshot['relationships']['declared_relationships'],
            ),
            'inferred_candidates' => $this->stripProfiling(
                $snapshot['relationships']['inferred_candidates'],
            ),
            'declared_profiling' => $this->extractProfiling(
                $snapshot['relationships']['declared_relationships'],
                true,
            ),
            'inferred_profiling' => $this->extractProfiling(
                $snapshot['relationships']['inferred_candidates'],
                false,
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @return list<array<string, mixed>>
     */
    private function stripProfiling(array $relationships): array
    {
        return array_map(
            static function (array $relationship): array {
                unset(
                    $relationship['confidence'],
                    $relationship['profiling'],
                    $relationship['diagnostics'],
                );

                return $relationship;
            },
            $relationships,
        );
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @return array<string, array<string, mixed>>
     */
    private function extractProfiling(
        array $relationships,
        bool $declared,
    ): array {
        $profiles = [];

        foreach ($relationships as $relationship) {
            $profile = array_intersect_key(
                $relationship,
                array_flip(['confidence', 'profiling', 'diagnostics']),
            );

            if ($profile !== []) {
                $profiles[$this->relationshipId($relationship, $declared)] =
                    $profile;
            }
        }

        return $profiles;
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @param array<string, mixed> $profiles
     * @return list<array<string, mixed>>
     */
    private function mergeProfiling(
        array $relationships,
        array $profiles,
        bool $declared,
    ): array {
        foreach ($relationships as &$relationship) {
            $id = $this->relationshipId($relationship, $declared);

            if (isset($profiles[$id])) {
                $relationship = array_replace($relationship, $profiles[$id]);
            }
        }
        unset($relationship);

        return $relationships;
    }

    /**
     * @param array<string, mixed> $relationship
     */
    private function relationshipId(
        array $relationship,
        bool $declared,
    ): string {
        $identity = [
            $relationship['source_table'] ?? null,
            $relationship['source_column'] ?? null,
            $relationship['target_table'] ?? null,
            $relationship['target_column'] ?? null,
        ];

        if ($declared) {
            array_unshift(
                $identity,
                $relationship['constraint_name'] ?? null,
                $relationship['target_schema'] ?? null,
            );
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $current
     */
    private function writeCurrentPointer(array $current): void
    {
        $json = $this->encode($current);
        $temporaryPath = tempnam($this->path, '.current-');

        if ($temporaryPath === false) {
            throw new RuntimeException(
                'Unable to create temporary active knowledge pointer.',
            );
        }

        try {
            $this->writeFile($temporaryPath, $json);

            if (!rename($temporaryPath, $this->path . '/current.json')) {
                throw new RuntimeException(
                    'Unable to activate the completed knowledge build.',
                );
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write knowledge library file.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, string $description): array
    {
        return $this->decode($this->readFile($path), $description);
    }

    private function readFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                'Knowledge library file does not exist.',
            );
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read knowledge library file.');
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json, string $description): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Knowledge {$description} contains invalid JSON.",
                previous: $exception,
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                "Knowledge {$description} must contain a JSON object or array.",
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function validateManifest(
        array $manifest,
        string $expectedBuildId,
    ): void {
        if (
            ($manifest['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($manifest['build_id'] ?? null) !== $expectedBuildId
            || !is_string($manifest['generated_at'] ?? null)
            || !is_array($manifest['source'] ?? null)
            || !is_string($manifest['source']['database'] ?? null)
            || !is_string(
                $manifest['source']['schema_fingerprint'] ?? null,
            )
            || !preg_match(
                '/^[a-f0-9]{64}$/',
                $manifest['source']['schema_fingerprint'],
            )
            || !is_array($manifest['schema_summary'] ?? null)
            || !is_array($manifest['profiling_config'] ?? null)
            || !is_array($manifest['artifacts'] ?? null)
        ) {
            throw new RuntimeException(
                'Knowledge library manifest has an invalid structure.',
            );
        }

        if (count($manifest['artifacts']) !== count(self::ARTIFACT_PATHS)) {
            throw new RuntimeException(
                'Knowledge library manifest has an invalid artifact list.',
            );
        }

        foreach (self::ARTIFACT_PATHS as $relativePath) {
            $checksum = $manifest['artifacts'][$relativePath] ?? null;

            if (
                !is_string($checksum)
                || !preg_match('/^[a-f0-9]{64}$/', $checksum)
            ) {
                throw new RuntimeException(
                    'Knowledge library manifest has an invalid artifact checksum.',
                );
            }
        }
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
