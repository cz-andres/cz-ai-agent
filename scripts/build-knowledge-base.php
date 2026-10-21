<?php
declare(strict_types=1);

use CzAiAgent\Config\DatabaseConfig;
use CzAiAgent\Database\KnowledgeBaseStore;
use CzAiAgent\Database\KnowledgeSnapshotBuilder;
use CzAiAgent\Database\MariaDbDataProfilingAdapter;
use CzAiAgent\Database\MariaDbSchemaDiscoveryAdapter;
use CzAiAgent\Database\RelationshipProfilingService;
use CzAiAgent\Database\SchemaDiscoveryService;
use CzAiAgent\Database\SchemaFingerprint;
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
