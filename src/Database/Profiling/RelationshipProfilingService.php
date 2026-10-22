<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Profiling;

use InvalidArgumentException;

final class RelationshipProfilingService
{
    public function __construct(
        private readonly DataProfilingAdapter $adapter,
    ) {
    }

    /**
     * Profile a list of relationship candidates.
     *
     * Each result preserves the original candidate separately from the
     * observed profiling evidence.
     *
     * @param list<array<string, mixed>> $candidates
     * @param array{
     *     database: string,
     *     tables: list<array<string, mixed>>,
     *     columns: list<array<string, mixed>>,
     *     foreign_keys: list<array<string, mixed>>
     * } $schema
     * @return list<array{
     *     candidate: array<string, mixed>,
     *     profiling: array<string, mixed>
     * }>
     */
    public function profileCandidates(
        array $candidates,
        array $schema,
        int $sampleLimit = 100,
    ): array {
        $results = [];

        foreach ($candidates as $candidate) {
            if (
                !isset(
                    $candidate['source_table'],
                    $candidate['source_column'],
                    $candidate['target_table'],
                    $candidate['target_column'],
                )
                || !is_string($candidate['source_table'])
                || !is_string($candidate['source_column'])
                || !is_string($candidate['target_table'])
                || !is_string($candidate['target_column'])
            ) {
                throw new InvalidArgumentException(
                    'Each candidate must contain valid source and target table and column names.'
                );
            }

            $results[] = [
                'candidate' => $candidate,
                'profiling' => $this->adapter->profileRelationship(
                    $candidate,
                    $schema,
                    $sampleLimit,
                ),
            ];
        }

        return $results;
    }
}
