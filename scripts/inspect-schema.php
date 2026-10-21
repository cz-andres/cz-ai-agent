<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CzAiAgent\Config\DatabaseConfig;
use CzAiAgent\Database\MariaDbSchemaDiscoveryAdapter;
use CzAiAgent\Database\SchemaDiscoveryService;

$config = DatabaseConfig::fromEnvironment();

$pdo = new PDO(
    "mysql:host={$config->host};port={$config->port};dbname={$config->database};charset=utf8mb4",
    $config->username,
    $config->password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

$adapter = new MariaDbSchemaDiscoveryAdapter(
    $pdo,
    $config->database,
);

$schema = (new SchemaDiscoveryService($adapter))->discover();

$columnsByTable = [];
$primaryKeysByTable = [];
$foreignKeysByTable = [];

foreach ($schema['columns'] as $column) {
    $tableName = $column['table_name'];

    $columnsByTable[$tableName][] = [
        'name' => $column['name'],
        'type' => $column['native_type'],
        'data_type' => $column['data_type'],
        'nullable' => $column['nullable'],
        'indexed' => $column['is_indexed'],
        'unique' => $column['is_unique'],
    ];

    if ($column['is_primary_key']) {
        $primaryKeysByTable[$tableName][] = $column['name'];
    }
}

foreach ($schema['foreign_keys'] as $foreignKey) {
    $foreignKeysByTable[$foreignKey['source_table']][] = [
        'column' => $foreignKey['source_column'],
        'constraint' => $foreignKey['name'],
        'target_table' => $foreignKey['target_table'],
        'target_column' => $foreignKey['target_column'],
    ];
}

$tables = [];

foreach ($schema['tables'] as $table) {
    $name = $table['name'];

    $tables[] = [
        'name' => $name,
        'type' => $table['type'],
        'comment' => $table['comment'],
        'column_count' => count($columnsByTable[$name] ?? []),
        'primary_keys' => $primaryKeysByTable[$name] ?? [],
        'columns' => $columnsByTable[$name] ?? [],
        'declared_foreign_keys' => $foreignKeysByTable[$name] ?? [],
    ];
}

echo json_encode([
    'database' => $schema['database'],
    'table_count' => count($tables),
    'column_count' => count($schema['columns']),
    'foreign_key_column_mapping_count' => count($schema['foreign_keys']),
    'tables' => $tables,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;