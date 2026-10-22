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

$snapshot = $builder->build(
    sampleLimit: 100,
    maxDeclaredToProfile: 5,
    maxInferredToProfile: 20,
);

$store = new KnowledgeBaseStore(
    dirname(__DIR__) . '/knowledge/snapshot.json',
);

$store->save($snapshot);

echo json_encode([
        'status' => 'saved',
        'path' => 'knowledge/snapshot.json',
        'generated_at' => $snapshot['generated_at'],
        'schema_fingerprint' =>
            $snapshot['source']['schema_fingerprint'],
        'schema_summary' => $snapshot['schema_summary'],
        'declared_relationships' =>
            count($snapshot['relationships']['declared_relationships']),
        'inferred_candidates' =>
            count($snapshot['relationships']['inferred_candidates']),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
