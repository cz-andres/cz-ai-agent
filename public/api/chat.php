<?php
declare(strict_types=1);

use CzAiAgent\Agent\DatabaseQuestionAgent;
use CzAiAgent\Agent\EvidenceAnswerGenerator;
use CzAiAgent\Agent\QueryPlanGenerator;
use CzAiAgent\Config\DatabaseConfig;
use CzAiAgent\Config\LlmConfig;
use CzAiAgent\Database\KnowledgeBaseLoader;
use CzAiAgent\Database\KnowledgeBaseStore;
use CzAiAgent\Database\MariaDbSchemaDiscoveryAdapter;
use CzAiAgent\Database\ReadOnlyDatabaseConnectionFactory;
use CzAiAgent\Database\ReadOnlyQueryExecutor;
use CzAiAgent\Database\SchemaContextRetriever;
use CzAiAgent\Database\SchemaDiscoveryService;
use CzAiAgent\Llm\LlmAdapterFactory;

require __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

try {
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false) {
        throw new RuntimeException('Could not read the request body.');
    }

    $payload = json_decode(
        $rawBody,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (!is_array($payload) || array_is_list($payload)) {
        throw new InvalidArgumentException(
            'The request body must be a JSON object.',
        );
    }

    $question = $payload['question'] ?? null;

    if (!is_string($question)) {
        throw new InvalidArgumentException(
            'The question must be a string.',
        );
    }

    $question = trim($question);

    if ($question === '') {
        throw new InvalidArgumentException('Please enter a question.');
    }

    if (mb_strlen($question) > 4000) {
        throw new InvalidArgumentException(
            'The question must not exceed 4000 characters.',
        );
    }

    $config = LlmConfig::fromEnvironment();
    $llm = (new LlmAdapterFactory($config))->create();

    $databaseConfig = DatabaseConfig::fromEnvironment();
    $pdo = ReadOnlyDatabaseConnectionFactory::connect($databaseConfig);

    $schemaDiscovery = new SchemaDiscoveryService(
        new MariaDbSchemaDiscoveryAdapter(
            $pdo,
            $databaseConfig->database,
        ),
    );

    $knowledgeBaseStore = new KnowledgeBaseStore(
        dirname(__DIR__, 2) . '/knowledge/snapshot.json',
    );
    $knowledgeBaseLoader = new KnowledgeBaseLoader(
        $knowledgeBaseStore,
    );

    $schemaContextRetriever = new SchemaContextRetriever(
        knowledgeBaseLoader: $knowledgeBaseLoader,
    );

    $agent = new DatabaseQuestionAgent(
        schemaContextRetriever: $schemaContextRetriever,
        schemaDiscovery: $schemaDiscovery,
        knowledgeBaseLoader: $knowledgeBaseLoader,
        queryPlanGenerator: new QueryPlanGenerator($llm),
        queryExecutor: new ReadOnlyQueryExecutor($pdo),
        answerGenerator: new EvidenceAnswerGenerator($llm),
    );

    $response = $agent->ask($question);

    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'error' => $exception->getMessage(),
    ]);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode([
        'error' => 'The request body contains invalid JSON.',
    ]);
} catch (Throwable $exception) {
    error_log((string) $exception);

    http_response_code(502);
    echo json_encode([
        'error' => 'The request could not be completed. Check the application logs.',
    ]);
}