<?php
declare(strict_types=1);

use CzAiAgent\Agent\DatabaseQuestionAgent;
use CzAiAgent\Agent\EvidenceAnswerGenerator;
use CzAiAgent\Agent\QueryIntentGenerator;
use CzAiAgent\Agent\ClarificationRequiredException;
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
use CzAiAgent\Agent\InsufficientSchemaException;
use CzAiAgent\Agent\StaleSchemaException;

require __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Only POST requests are allowed.']);
    exit;
}

$requestId = bin2hex(random_bytes(8));
$stage = 'request_validation';

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

    $stage = 'service_initialization';
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

    $knowledgePath = getenv('KNOWLEDGE_LIBRARY_PATH')
        ?: dirname(__DIR__, 2) . '/knowledge';
    $knowledgeBaseStore = new KnowledgeBaseStore($knowledgePath);
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
        queryIntentGenerator: new QueryIntentGenerator($llm),
        queryIntentPlanBuilder: new QueryIntentPlanBuilder(),
        queryExecutor: new ReadOnlyQueryExecutor($pdo),
        answerGenerator: new EvidenceAnswerGenerator($llm),
    );

    $stage = 'database_question_agent';
    $response = $agent->ask($question);
    $response['request_id'] = $requestId;

    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode([
        'error' => 'The request body contains invalid JSON.',
        'request_id' => $requestId,
    ]);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'error' => $exception->getMessage(),
        'request_id' => $requestId,
    ]);
} catch (InsufficientSchemaException $exception) {
    http_response_code(422);
    echo json_encode([
        'error' => $exception->getMessage(),
        'request_id' => $requestId,
    ]);
} catch (ClarificationRequiredException $exception) {
    http_response_code(422);
    echo json_encode([
        'error' => $exception->getMessage(),
        'request_id' => $requestId,
    ]);
} catch (StaleSchemaException $exception) {
    http_response_code(409);
    echo json_encode([
        'error' => $exception->getMessage(),
        'request_id' => $requestId,
    ]);
} catch (Throwable $exception) {
    error_log(json_encode([
        'event' => 'database_chat_request_failed',
        'request_id' => $requestId,
        'stage' => $stage,
        'exception_type' => get_class($exception),
    ], JSON_THROW_ON_ERROR));
    http_response_code(502);
    echo json_encode([
        'error' => 'The request could not be completed. Share the reference ID with an administrator.',
        'request_id' => $requestId,
    ]);
}