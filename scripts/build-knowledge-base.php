<?php
declare(strict_types=1);

use CzAiAgent\Config\DatabaseConfig;
use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use CzAiAgent\Database\Knowledge\KnowledgeSnapshotBuilder;
use CzAiAgent\Database\Profiling\MariaDbDataProfilingAdapter;
use CzAiAgent\Database\Discovery\MariaDbSchemaDiscoveryAdapter;
use CzAiAgent\Database\Connection\ReadOnlyDatabaseConnectionFactory;
use CzAiAgent\Database\Profiling\RelationshipProfilingService;
use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use CzAiAgent\Database\Knowledge\SchemaFingerprint;
use CzAiAgent\Database\Profiling\SchemaRelationshipAnalyzer;
use CzAiAgent\Database\Profiling\SchemaRelationshipProfilingService;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = DatabaseConfig::fromEnvironment();
$pdo = ReadOnlyDatabaseConnectionFactory::connect($config);

$discovery = new SchemaDiscoveryService(
    new MariaDbSchemaDiscoveryAdapter($pdo, $config->database),
);

$relationshipProfiler = new SchemaRelationshipProfilingService(
    new SchemaRelationshipAnalyzer(),
    new RelationshipProfilingService(
        new MariaDbDataProfilingAdapter($pdo),
    ),
);

$builder = new KnowledgeSnapshotBuilder(
    $discovery,
    $relationshipProfiler,
    new SchemaFingerprint(),
);

$sampleLimit = environmentInteger('KNOWLEDGE_PROFILE_SAMPLE_LIMIT', 100);
$maxInferredToProfile = environmentInteger(
    'KNOWLEDGE_MAX_INFERRED_TO_PROFILE',
    20,
);

$snapshot = $builder->build(
    sampleLimit: $sampleLimit,
    maxInferredToProfile: $maxInferredToProfile,
);

$knowledgePath = getenv('KNOWLEDGE_LIBRARY_PATH')
    ?: dirname(__DIR__) . '/knowledge';
$store = new KnowledgeBaseStore($knowledgePath);

$buildId = $store->save($snapshot);

echo json_encode([
        'status' => 'saved',
        'path' => $knowledgePath . '/current.json',
        'build_id' => $buildId,
        'generated_at' => $snapshot['generated_at'],
        'schema_fingerprint' =>
            $snapshot['source']['schema_fingerprint'],
        'schema_summary' => $snapshot['schema_summary'],
        'declared_relationships' =>
            count($snapshot['relationships']['declared_relationships']),
        'inferred_candidates' =>
            count($snapshot['relationships']['inferred_candidates']),
        'coverage' => $snapshot['coverage'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

function environmentInteger(string $name, int $default): int
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT);

    if (!is_int($integer)) {
        throw new InvalidArgumentException(
            "Environment variable {$name} must be an integer.",
        );
    }

    return $integer;
}
