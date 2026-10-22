<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Knowledge;

use JsonException;
use RuntimeException;

final class KnowledgeBaseStore
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function save(array $snapshot): void
    {
        $this->validate($snapshot);

        $directory = dirname($this->path);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Unable to create knowledge directory.',
            );
        }

        $json = json_encode(
                $snapshot,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;

        $temporaryPath = tempnam($directory, '.knowledge-');

        if ($temporaryPath === false) {
            throw new RuntimeException(
                'Unable to create temporary knowledge file.',
            );
        }

        try {
            if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                throw new RuntimeException(
                    'Unable to write knowledge snapshot.',
                );
            }

            if (!rename($temporaryPath, $this->path)) {
                throw new RuntimeException(
                    'Unable to replace knowledge snapshot.',
                );
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            throw new RuntimeException(
                'Knowledge snapshot does not exist.',
            );
        }

        $json = file_get_contents($this->path);

        if ($json === false) {
            throw new RuntimeException(
                'Unable to read knowledge snapshot.',
            );
        }

        try {
            $snapshot = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Knowledge snapshot contains invalid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($snapshot)) {
            throw new RuntimeException(
                'Knowledge snapshot must contain a JSON object.',
            );
        }

        $this->validate($snapshot);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function validate(array $snapshot): void
    {
        if (($snapshot['format_version'] ?? null) !== 1) {
            throw new RuntimeException(
                'Unsupported knowledge snapshot format version.',
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
                    "Knowledge snapshot is missing required field: {$key}",
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
                'Knowledge snapshot has an invalid structure.',
            );
        }
    }
}
