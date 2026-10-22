<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Profiling;

interface DataProfilingAdapter
{
    /**
     * Compare sampled, non-null values in a source column with a target column.
     *
     * The supplied schema must be used to validate all table and column names
     * before querying. Actual database values must not be included in the result.
     *
     * @param array<string, mixed> $candidate
     * @param array{
     *     database: string,
     *     tables: list<array<string, mixed>>,
     *     columns: list<array<string, mixed>>,
     *     foreign_keys: list<array<string, mixed>>
     * } $schema
     * @return array{
     *     source_table: string,
     *     source_column: string,
     *     target_table: string,
     *     target_column: string,
     *     sampled_value_count: int,
     *     matched_value_count: int,
     *     match_rate: float|null,
     *     status: string
     * }
     */
    public function profileRelationship(
        array $candidate,
        array $schema,
        int $sampleLimit = 500,
    ): array;
}