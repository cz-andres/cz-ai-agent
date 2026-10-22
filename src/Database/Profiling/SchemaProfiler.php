<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Profiling;

final class SchemaProfiler
{
    /**
     * Summarise normalised schema metadata and identify possible relationships.
     *
     * Relationship candidates are heuristic and are not confirmed foreign keys.
     *
     * @param array{
     *     database: string,
     *     tables: list<array<string, mixed>>,
     *     columns: list<array<string, mixed>>,
     *     foreign_keys: list<array<string, mixed>>
     * } $schema
     * @return array<string, mixed>
     */
    public function profile(array $schema): array
    {
        $tables = [];

        foreach ($schema['tables'] as $table) {
            $name = (string) $table['name'];

            $tables[$name] = [
                'table_name' => $name,
                'table_type' => $table['type'],
                'table_comment' => $table['comment'],
                'columns' => [],
                'primary_key_columns' => [],
                'indexed_columns' => [],
                'relationship_candidates' => [],
            ];
        }

        foreach ($schema['columns'] as $column) {
            $tableName = (string) $column['table_name'];

            if (!isset($tables[$tableName])) {
                continue;
            }

            $columnName = (string) $column['name'];

            $tables[$tableName]['columns'][] = [
                'column_name' => $columnName,
                'column_type' => $column['native_type'],
                'data_type' => $column['data_type'],
                'is_nullable' => $column['nullable'],
                'is_primary_key' => $column['is_primary_key'],
                'is_indexed' => $column['is_indexed'],
                'is_unique' => $column['is_unique'],
                'auto_increment' => $column['auto_increment'],
                'column_comment' => $column['comment'],
            ];

            if ($column['is_primary_key']) {
                $tables[$tableName]['primary_key_columns'][] = $columnName;
            }

            if ($column['is_indexed']) {
                $tables[$tableName]['indexed_columns'][] = $columnName;
            }
        }

        $tableNames = array_keys($tables);

        foreach ($tables as $tableName => &$table) {
            foreach ($table['columns'] as $column) {
                $columnName = $column['column_name'];

                if (!preg_match('/^(.+)_id$/i', $columnName, $matches)) {
                    continue;
                }

                $stem = strtolower($matches[1]);
                $possibleTargets = [];

                foreach ($tableNames as $candidateTableName) {
                    if ($candidateTableName === $tableName) {
                        continue;
                    }

                    $normalisedTableName = strtolower($candidateTableName);
                    $singularCandidate = rtrim($normalisedTableName, 's');

                    if (
                        $normalisedTableName === $stem
                        || $normalisedTableName === $stem . 's'
                        || $singularCandidate === $stem
                    ) {
                        $possibleTargets[] = $candidateTableName;
                    }
                }

                $table['relationship_candidates'][] = [
                    'column' => $columnName,
                    'possible_target_tables' => $possibleTargets,
                    'status' => 'unverified_candidate',
                ];
            }
        }

        unset($table);

        return [
            'database' => $schema['database'],
            'table_count' => count($tables),
            'column_count' => count($schema['columns']),
            'declared_foreign_key_count' => count($schema['foreign_keys']),
            'tables' => array_values($tables),
        ];
    }
}