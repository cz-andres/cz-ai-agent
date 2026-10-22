<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Discovery;

use PDO;

final class MariaDbSchemaDiscoveryAdapter implements SchemaDiscoveryAdapter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
    ) {
    }

    public function discover(): array
    {
        $rawTables = $this->fetchAll(
            <<<'SQL'
            SELECT
                TABLE_NAME AS name,
                TABLE_TYPE AS type,
                TABLE_COMMENT AS comment
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :database
            ORDER BY TABLE_NAME
            SQL
        );

        $rawColumns = $this->fetchAll(
            <<<'SQL'
            SELECT
                TABLE_NAME AS table_name,
                COLUMN_NAME AS name,
                ORDINAL_POSITION AS ordinal_position,
                DATA_TYPE AS data_type,
                COLUMN_TYPE AS native_type,
                IS_NULLABLE AS nullable,
                COLUMN_DEFAULT AS column_default,
                COLUMN_KEY AS column_key,
                EXTRA AS extra,
                COLUMN_COMMENT AS comment
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :database
            ORDER BY TABLE_NAME, ORDINAL_POSITION
            SQL
        );

        $rawIndexes = $this->fetchAll(
            <<<'SQL'
            SELECT
                TABLE_NAME AS table_name,
                INDEX_NAME AS name,
                NON_UNIQUE AS non_unique,
                SEQ_IN_INDEX AS ordinal_position,
                COLUMN_NAME AS column_name,
                SUB_PART AS prefix_length,
                INDEX_TYPE AS type
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = :database
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
            SQL
        );

        $rawForeignKeys = $this->fetchAll(
            <<<'SQL'
            SELECT
                CONSTRAINT_NAME AS name,
                ORDINAL_POSITION AS ordinal_position,
                TABLE_NAME AS source_table,
                COLUMN_NAME AS source_column,
                REFERENCED_TABLE_SCHEMA AS target_schema,
                REFERENCED_TABLE_NAME AS target_table,
                REFERENCED_COLUMN_NAME AS target_column
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :database
              AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION
            SQL
        );

        $tables = [];

        foreach ($rawTables as $table) {
            $tables[] = [
                'name' => $table['name'],
                'type' => $table['type'],
                'comment' => $table['comment'],
            ];
        }

        $indexesByTable = [];
        $indexMembership = [];
        $singleColumnUnique = [];

        foreach ($rawIndexes as $indexRow) {
            $tableName = (string) $indexRow['table_name'];
            $indexName = (string) $indexRow['name'];
            $groupKey = json_encode(
                [$tableName, $indexName],
                JSON_THROW_ON_ERROR,
            );
            $isUnique = (int) $indexRow['non_unique'] === 0;
            $columnName = is_string($indexRow['column_name'])
                ? $indexRow['column_name']
                : null;

            $indexesByTable[$groupKey] ??= [
                'table_name' => $tableName,
                'name' => $indexName,
                'is_unique' => $isUnique,
                'type' => (string) $indexRow['type'],
                'columns' => [],
            ];
            $indexesByTable[$groupKey]['columns'][] = [
                'name' => $columnName,
                'ordinal_position' => (int) $indexRow['ordinal_position'],
                'prefix_length' => $indexRow['prefix_length'] === null
                    ? null
                    : (int) $indexRow['prefix_length'],
            ];

            if ($columnName !== null) {
                $indexMembership[$tableName][$columnName] = true;

                if ($isUnique && $indexName === 'PRIMARY') {
                    $primaryColumns[$tableName][] = $columnName;
                }
            }
        }

        foreach ($indexesByTable as $index) {
            if (
                !$index['is_unique']
                || count($index['columns']) !== 1
                || !is_string($index['columns'][0]['name'])
            ) {
                continue;
            }

            $singleColumnUnique[$index['table_name']][
                $index['columns'][0]['name']
            ] = true;
        }

        $indexes = array_values($indexesByTable);
        $columns = [];

        foreach ($rawColumns as $column) {
            $tableName = $column['table_name'];
            $columnName = $column['name'];

            $columns[] = [
                'table_name' => $tableName,
                'name' => $columnName,
                'ordinal_position' => (int) $column['ordinal_position'],
                'data_type' => strtolower($column['data_type']),
                'native_type' => $column['native_type'],
                'nullable' => $column['nullable'] === 'YES',
                'default' => $column['column_default'],
                'is_primary_key' => isset(
                    $indexMembership[$tableName][$columnName],
                ) && in_array(
                    $columnName,
                    $primaryColumns[$tableName] ?? [],
                    true,
                ),
                'is_indexed' => isset(
                    $indexMembership[$tableName][$columnName],
                ),
                'is_unique' => isset(
                    $singleColumnUnique[$tableName][$columnName],
                ),
                'auto_increment' => stripos($column['extra'], 'auto_increment') !== false,
                'comment' => $column['comment'],
            ];
        }

        $foreignKeyConstraints = [];

        foreach ($rawForeignKeys as $foreignKey) {
            $groupKey = json_encode([
                $foreignKey['source_table'],
                $foreignKey['name'],
                $foreignKey['target_schema'],
                $foreignKey['target_table'],
            ], JSON_THROW_ON_ERROR);

            $foreignKeyConstraints[$groupKey] ??= [
                'name' => $foreignKey['name'],
                'source_table' => $foreignKey['source_table'],
                'target_schema' => $foreignKey['target_schema'],
                'target_table' => $foreignKey['target_table'],
                'columns' => [],
            ];
            $foreignKeyConstraints[$groupKey]['columns'][] = [
                'source_column' => $foreignKey['source_column'],
                'target_column' => $foreignKey['target_column'],
                'ordinal_position' => (int) $foreignKey['ordinal_position'],
            ];
        }

        return [
            'database' => $this->database,
            'tables' => $tables,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $rawForeignKeys,
            'foreign_key_constraints' => array_values($foreignKeyConstraints),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'database' => $this->database,
        ]);

        return $statement->fetchAll();
    }
}