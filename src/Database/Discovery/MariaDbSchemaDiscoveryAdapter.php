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

        $columns = [];

        foreach ($rawColumns as $column) {
            $keyType = $column['column_key'];

            $columns[] = [
                'table_name' => $column['table_name'],
                'name' => $column['name'],
                'ordinal_position' => (int) $column['ordinal_position'],
                'data_type' => strtolower($column['data_type']),
                'native_type' => $column['native_type'],
                'nullable' => $column['nullable'] === 'YES',
                'default' => $column['column_default'],
                'is_primary_key' => $keyType === 'PRI',
                'is_indexed' => $keyType !== '',
                'is_unique' => in_array($keyType, ['PRI', 'UNI'], true),
                'auto_increment' => stripos($column['extra'], 'auto_increment') !== false,
                'comment' => $column['comment'],
            ];
        }

        return [
            'database' => $this->database,
            'tables' => $tables,
            'columns' => $columns,
            'foreign_keys' => $rawForeignKeys,
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