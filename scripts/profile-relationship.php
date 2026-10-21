<?php
declare(strict_types=1);

use CzAiAgent\Config\DatabaseConfig;
use CzAiAgent\Database\MariaDbDataProfilingAdapter;
use CzAiAgent\Database\MariaDbSchemaDiscoveryAdapter;
use CzAiAgent\Database\RelationshipProfilingService;
use CzAiAgent\Database\SchemaDiscoveryService;
use CzAiAgent\Database\SchemaRelationshipAnalyzer;
use CzAiAgent\Database\SchemaRelationshipProfilingService;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = DatabaseConfig::fromEnvironment();

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config->host,
        $config->port,
        $config->database,
    ),
    $config->username,
    $config->password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);

$schema = (new SchemaDiscoveryService(
    new MariaDbSchemaDiscoveryAdapter($pdo, $config->database),
))->discover();

$service = new SchemaRelationshipProfilingService(
    new SchemaRelationshipAnalyzer(),
    new RelationshipProfilingService(
        new MariaDbDataProfilingAdapter($pdo),
    ),
);

$result = $service->analyzeAndProfile(
    $schema,
    sampleLimit: 100,
    maxDeclaredToProfile: 5,
    maxInferredToProfile: 20,
);

$summarise = static function (array $relationships): array {
    $statusCounts = [];
    $primaryClassificationCounts = [];
    $secondaryFlagCounts = [];

    foreach ($relationships as $relationship) {
        $status = $relationship['profiling']['status'] ?? 'unknown';
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

        $diagnostics = $relationship['diagnostics'] ?? [];
        $classification = $diagnostics['classification'] ?? 'unknown';

        $primaryClassificationCounts[$classification] =
            ($primaryClassificationCounts[$classification] ?? 0) + 1;

        $classifications = $diagnostics['classifications']
            ?? [$classification];

        foreach (array_unique($classifications) as $flag) {
            if ($flag === $classification) {
                continue;
            }

            $secondaryFlagCounts[$flag] =
                ($secondaryFlagCounts[$flag] ?? 0) + 1;
        }
    }

    ksort($statusCounts);
    ksort($primaryClassificationCounts);
    ksort($secondaryFlagCounts);

    $visibleRelationships = array_slice($relationships, 0, 10);
    $visibleKeys = [];

    foreach ($visibleRelationships as $relationship) {
        $key = json_encode([
            $relationship['source_table'],
            $relationship['source_column'],
            $relationship['target_table'],
            $relationship['target_column'],
        ], JSON_THROW_ON_ERROR);

        $visibleKeys[$key] = true;
    }

    foreach ($relationships as $relationship) {
        if (($relationship['profiling']['status'] ?? null) !== 'profiled') {
            continue;
        }

        $key = json_encode([
            $relationship['source_table'],
            $relationship['source_column'],
            $relationship['target_table'],
            $relationship['target_column'],
        ], JSON_THROW_ON_ERROR);

        if (isset($visibleKeys[$key])) {
            continue;
        }

        $visibleRelationships[] = $relationship;
        $visibleKeys[$key] = true;
    }

    return [
        'total' => count($relationships),
        'profiling_status_counts' => $statusCounts,
        'diagnostic_summary' => [
            'primary_classification_counts' =>
                $primaryClassificationCounts,
            'secondary_flag_counts' => $secondaryFlagCounts,
        ],
        'relationships' => $visibleRelationships,
    ];
};

$report = [
    'database' => $schema['database'],
    'schema_summary' => [
        'table_count' => count($schema['tables']),
        'column_count' => count($schema['columns']),
        'declared_foreign_key_count' => count($schema['foreign_keys']),
    ],
    'declared_relationships' => $summarise(
        $result['declared_relationships'],
    ),
    'inferred_candidates' => $summarise(
        $result['inferred_candidates'],
    ),
];

echo json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
) . PHP_EOL;