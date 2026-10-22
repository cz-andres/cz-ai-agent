<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Profiling;

use InvalidArgumentException;
use PDO;

final class MariaDbDataProfilingAdapter implements DataProfilingAdapter
{
    private const DEFAULT_SAMPLE_LIMIT = 500;
    private const MAX_SAMPLE_LIMIT = 5000;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function profileRelationship(
        array $candidate,
        array $schema,
        int $sampleLimit = self::DEFAULT_SAMPLE_LIMIT,
    ): array {
        if ($sampleLimit < 1 || $sampleLimit > self::MAX_SAMPLE_LIMIT) {
            throw new InvalidArgumentException(
                'Sample limit must be between 1 and '
                . self::MAX_SAMPLE_LIMIT . '.'
            );
        }

        $sourceTable = $this->requiredString($candidate, 'source_table');
        $targetTable = $this->requiredString($candidate, 'target_table');
        $columnPairs = $this->columnPairs($candidate);

        $database = $this->requiredString($schema, 'database');

        $columns = $this->indexColumns($schema);
        foreach ($columnPairs as $pair) {
            if (
                !isset(
                    $columns[$sourceTable][$pair['source_column']],
                    $columns[$targetTable][$pair['target_column']],
                )
            ) {
                throw new InvalidArgumentException(
                    'The candidate references a table or column absent from the discovered schema.'
                );
            }
        }

        $firstPair = $columnPairs[0];
        $baseResult = [
            'source_table' => $sourceTable,
            'source_column' => $firstPair['source_column'],
            'target_table' => $targetTable,
            'target_column' => $firstPair['target_column'],
            'column_pairs' => $columnPairs,
            'sampled_value_count' => 0,
            'matched_value_count' => 0,
            'match_rate' => null,
            'sample_limit' => $sampleLimit,
            'sampling_method' => 'non_null_source_rows_limit',
            'is_exhaustive' => null,
            'status' => 'not_profiled',
        ];

        foreach ($columnPairs as $pair) {
            $sourceMetadata = $columns[$sourceTable][$pair['source_column']];
            $targetMetadata = $columns[$targetTable][$pair['target_column']];

            if (
                $this->typeFamily((string) $sourceMetadata['data_type'])
                !== $this->typeFamily((string) $targetMetadata['data_type'])
            ) {
                return [
                    ...$baseResult,
                    'status' => 'incompatible_types',
                ];
            }
        }

        if (
            ($candidate['status'] ?? null) !== 'declared_foreign_key'
            && !($columns[$targetTable][$firstPair['target_column']]['is_unique'] ?? false)
        ) {
            return [
                ...$baseResult,
                'status' => 'target_not_unique',
            ];
        }

        $quotedDatabase = $this->quoteIdentifier($database);
        $quotedSourceTable = $this->quoteIdentifier($sourceTable);
        $quotedTargetTable = $this->quoteIdentifier($targetTable);
        $selectColumns = [];
        $notNullConditions = [];
        $matchConditions = [];

        foreach ($columnPairs as $index => $pair) {
            $sourceColumn = $this->quoteIdentifier($pair['source_column']);
            $targetColumn = $this->quoteIdentifier($pair['target_column']);
            $valueAlias = $this->quoteIdentifier('source_value_' . $index);
            $selectColumns[] = "{$sourceColumn} AS {$valueAlias}";
            $notNullConditions[] = "{$sourceColumn} IS NOT NULL";
            $matchConditions[] = "target_rows.{$targetColumn} = source_values.{$valueAlias}";
        }

        $sql = "
            SELECT
                COUNT(*) AS sampled_value_count,
                COALESCE(
                    SUM(
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM {$quotedDatabase}.{$quotedTargetTable} AS target_rows
                                WHERE " . implode(' AND ', $matchConditions) . "
                            )
                            THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS matched_value_count
            FROM (
                SELECT " . implode(', ', $selectColumns) . "
                FROM {$quotedDatabase}.{$quotedSourceTable}
                WHERE " . implode(' AND ', $notNullConditions) . "
                LIMIT {$sampleLimit}
            ) AS source_values
        ";

        $statement = $this->pdo->query($sql);
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            throw new \RuntimeException(
                'Unable to retrieve relationship profiling results.'
            );
        }

        $sampledCount = (int) $result['sampled_value_count'];
        $matchedCount = (int) $result['matched_value_count'];

        return [
            ...$baseResult,
            'sampled_value_count' => $sampledCount,
            'matched_value_count' => $matchedCount,
            'match_rate' => $sampledCount === 0
                ? null
                : $matchedCount / $sampledCount,
            'is_exhaustive' => $sampledCount < $sampleLimit,
            'status' => $sampledCount === 0
                ? 'no_sampled_values'
                : 'profiled',
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return non-empty-list<array{source_column: string, target_column: string}>
     */
    private function columnPairs(array $candidate): array
    {
        $pairs = $candidate['column_pairs'] ?? null;

        if ($pairs === null) {
            $pairs = [[
                'source_column' => $this->requiredString(
                    $candidate,
                    'source_column',
                ),
                'target_column' => $this->requiredString(
                    $candidate,
                    'target_column',
                ),
            ]];
        }

        if (!is_array($pairs) || !array_is_list($pairs) || $pairs === []) {
            throw new InvalidArgumentException(
                'The relationship must contain at least one column pair.',
            );
        }

        $validated = [];

        foreach ($pairs as $pair) {
            if (
                !is_array($pair)
                || !is_string($pair['source_column'] ?? null)
                || !is_string($pair['target_column'] ?? null)
            ) {
                throw new InvalidArgumentException(
                    'Each relationship column pair must contain source and target columns.',
                );
            }

            $validated[] = [
                'source_column' => $pair['source_column'],
                'target_column' => $pair['target_column'],
            ];
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function indexColumns(array $schema): array
    {
        $indexed = [];

        foreach ($schema['columns'] ?? [] as $column) {
            if (
                !isset($column['table_name'], $column['name'])
                || !is_string($column['table_name'])
                || !is_string($column['name'])
            ) {
                continue;
            }

            $indexed[$column['table_name']][$column['name']] = $column;
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Missing or invalid required value: {$key}."
            );
        }

        return $value;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function typeFamily(string $dataType): string
    {
        $type = strtolower(trim($dataType));

        if (in_array($type, [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'serial', 'bigserial',
        ], true)) {
            return 'integer';
        }

        if (in_array($type, [
            'decimal', 'numeric', 'float', 'double', 'real',
            'double precision', 'money',
        ], true)) {
            return 'numeric';
        }

        if (in_array($type, [
            'char', 'varchar', 'tinytext', 'text', 'mediumtext',
            'longtext', 'enum', 'set', 'character varying',
            'character', 'citext',
        ], true)) {
            return 'string';
        }

        if (in_array($type, [
            'date', 'datetime', 'timestamp', 'time', 'year',
            'timestamp with time zone', 'timestamp without time zone',
            'time with time zone', 'time without time zone',
        ], true)) {
            return 'date_or_time';
        }

        if (in_array($type, [
            'binary', 'varbinary', 'tinyblob', 'blob',
            'mediumblob', 'longblob', 'bytea',
        ], true)) {
            return 'binary';
        }

        if (in_array($type, ['bool', 'boolean'], true)) {
            return 'boolean';
        }

        if (in_array($type, ['json', 'jsonb'], true)) {
            return 'json';
        }

        return 'other';
    }
}