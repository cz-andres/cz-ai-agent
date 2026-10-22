<?php
declare(strict_types=1);

use CzAiAgent\Agent\DatabaseQuestionAgent;
use CzAiAgent\Agent\EvidenceAnswerGenerator;
use CzAiAgent\Agent\QueryIntentGenerator;
use CzAiAgent\Config\DatabaseConfig;
use CzAiAgent\Config\LlmConfig;
use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use CzAiAgent\Database\Discovery\MariaDbSchemaDiscoveryAdapter;
use CzAiAgent\Database\Connection\ReadOnlyDatabaseConnectionFactory;
use CzAiAgent\Database\Query\ReadOnlyQueryExecutor;
use CzAiAgent\Database\Query\QueryIntentPlanBuilder;
use CzAiAgent\Database\Context\SchemaContextRetriever;
use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use CzAiAgent\Llm\LlmAdapterFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

function reportStage(string $stage, string $status, array $details = []): void
{
    echo json_encode([
            'stage' => $stage,
            'status' => $status,
            'details' => $details,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . PHP_EOL;
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only run from the CLI.\n");
    exit(2);
}

$question = trim(implode(' ', array_slice($argv, 1)));

if ($question === '') {
    fwrite(
        STDERR,
        "Usage: php scripts/smoke-test.php \"Your database question\"\n",
    );
    exit(2);
}

if (mb_strlen($question) > 4000) {
    fwrite(STDERR, "The question must not exceed 4000 characters.\n");
    exit(2);
}

$stage = 'database_connection';

try {
    $stage = 'database_connection';
    $databaseConfig = DatabaseConfig::fromEnvironment();
    $pdo = ReadOnlyDatabaseConnectionFactory::connect($databaseConfig);

    $schemaDiscovery = new SchemaDiscoveryService(
        new MariaDbSchemaDiscoveryAdapter(
            $pdo,
            $databaseConfig->database,
        ),
    );

    $stage = 'live_schema_discovery';
    $currentSchema = $schemaDiscovery->discover();

    reportStage('live_schema_discovery', 'passed', [
        'database' => $databaseConfig->database,
        'tables' => count($currentSchema['tables'] ?? []),
        'columns' => count($currentSchema['columns'] ?? []),
        'foreign_keys' => count($currentSchema['foreign_keys'] ?? []),
    ]);

    $store = new KnowledgeBaseStore(
        dirname(__DIR__) . '/knowledge/snapshot.json',
    );
    $loader = new KnowledgeBaseLoader($store);
    $stage = 'knowledge_base_validation';
    $knowledge = $loader->load($currentSchema);

    if (($knowledge['schema_status'] ?? null) !== 'current') {
        reportStage('knowledge_base_validation', 'failed', [
            'schema_status' => $knowledge['schema_status'] ?? 'unknown',
            'action' => 'Rebuild the knowledge base before querying.',
        ]);
        exit(1);
    }

    $snapshot = $knowledge['snapshot'] ?? [];

    reportStage('knowledge_base_validation', 'passed', [
        'schema_status' => $knowledge['schema_status'],
        'fingerprint' => $snapshot['source']['schema_fingerprint'] ?? null,
    ]);

    $stage = 'llm_initialization';
    $llmConfig = LlmConfig::fromEnvironment();
    $llm = (new LlmAdapterFactory($llmConfig))->create();

    reportStage('llm_configuration', 'passed', [
        'provider' => $llmConfig->provider,
        'model' => $llmConfig->provider === 'ollama'
            ? $llmConfig->ollamaModel
            : 'configured adapter',
    ]);

    $retriever = new SchemaContextRetriever(
        knowledgeBaseLoader: $loader,
    );

    $agent = new DatabaseQuestionAgent(
        schemaContextRetriever: $retriever,
        schemaDiscovery: $schemaDiscovery,
        knowledgeBaseLoader: $loader,
        queryIntentGenerator: new QueryIntentGenerator($llm),
        queryIntentPlanBuilder: new QueryIntentPlanBuilder(),
        queryExecutor: new ReadOnlyQueryExecutor($pdo),
        answerGenerator: new EvidenceAnswerGenerator($llm),
    );

    $stage = 'agent_query_and_answer';
    $result = $agent->ask($question);

    reportStage('agent_query_and_answer', 'passed', [
        'answer' => $result['answer'],
        'provider' => $result['provider'],
        'model' => $result['model'],
        'evidence' => $result['evidence'],
        'diagnostics' => $result['diagnostics'],
    ]);

    reportStage('smoke_test', 'passed');
} catch (Throwable $exception) {
    $exceptions = [];
    $currentException = $exception;

    while ($currentException !== null && count($exceptions) < 5) {
        $message = $currentException->getMessage();

        $exceptions[] = [
            'type' => get_class($currentException),
            'message' => mb_substr($message, 0, 500),
        ];

        $currentException = $currentException->getPrevious();
    }

    reportStage('smoke_test', 'failed', [
        'failed_stage' => $stage,
        'exceptions' => $exceptions,
    ]);

    fwrite(STDERR, "Smoke test failed at stage: {$stage}\n");
    exit(1);
}
