<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Agent;

use CzAiAgent\Agent\DatabaseQuestionAgent;
use CzAiAgent\Agent\EvidenceAnswerGenerator;
use CzAiAgent\Agent\QueryIntentGenerator;
use CzAiAgent\Config\LlmConfig;
use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use CzAiAgent\Database\Knowledge\KnowledgeBaseStore;
use CzAiAgent\Database\Query\ReadOnlyQueryExecutor;
use CzAiAgent\Database\Query\QueryIntentPlanBuilder;
use CzAiAgent\Database\Context\SchemaContextRetriever;
use CzAiAgent\Database\Discovery\SchemaDiscoveryAdapter;
use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use CzAiAgent\Database\Knowledge\SchemaFingerprint;
use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use CzAiAgent\Llm\LlmResponse;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseQuestionAgentTest extends TestCase
{
    private string $directory;
    private string $knowledgePath;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/cz-ai-agent-agent-'
            . bin2hex(random_bytes(8));

        mkdir($this->directory, 0775, true);
        $this->knowledgePath = $this->directory . '/knowledge';

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            'CREATE TABLE case_records (id INTEGER PRIMARY KEY, state TEXT)'
        );
        $this->pdo->exec(
            "INSERT INTO case_records (id, state) VALUES (1, 'QLD')"
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $this->removeDirectory($this->directory);
        }
    }

    public function testAnswersQuestionUsingExecutedDatabaseEvidence(): void
    {
        $schema = $this->schema();
        $this->saveSnapshot($schema);

        $llm = new class implements LlmAdapter {
            public int $calls = 0;
            public ?string $answerUserMessage = null;

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->calls++;

                if (str_contains($request->systemPrompt, 'query intent')) {
                    return new LlmResponse(
                        json_encode([
                            'select' => ['case_records.id', 'case_records.state'],
                        ], JSON_THROW_ON_ERROR),
                        'test',
                        'synthetic-model',
                    );
                }

                $this->answerUserMessage = $request->userMessage;

                return new LlmResponse(
                    'The matching record has state QLD.',
                    'test',
                    'synthetic-model',
                );
            }
        };

        $agent = $this->agent($schema, $llm);

        $result = $agent->ask('Show case records and state');

        self::assertNotNull($llm->answerUserMessage);
        self::assertStringContainsString(
            '"state":"QLD"',
            $llm->answerUserMessage,
        );
        self::assertSame(
            'The matching record has state QLD.',
            $result['answer'],
        );
        self::assertSame('test', $result['provider']);
        self::assertSame('synthetic-model', $result['model']);
        self::assertSame(
            [
                'source' => 'read_only_database_query',
                'tables' => ['case_records'],
                'returned_rows' => 1,
            ],
            $result['evidence'],
        );
        self::assertSame(2, $llm->calls);
        self::assertArrayNotHasKey('rows', $result);
        self::assertContains(
            $result['diagnostics']['retrieval_confidence'],
            ['high', 'medium', 'low'],
        );
        self::assertSame(1, $result['diagnostics']['matched_table_count']);
        self::assertSame(2, $result['diagnostics']['candidate_field_count']);
        self::assertSame(0, $result['diagnostics']['join_count']);
        foreach ([
            'schema_discovery',
            'knowledge_validation',
            'context_retrieval',
            'planning',
            'query_execution',
            'answer_generation',
            'total',
        ] as $stage) {
            self::assertIsFloat($result['diagnostics']['timings_ms'][$stage]);
        }
    }

    public function testRetriesAnInvalidIntentOnceWithValidationFeedback(): void
    {
        $schema = $this->schema();
        $this->saveSnapshot($schema);

        $llm = new class implements LlmAdapter {
            public int $calls = 0;
            public ?string $retryFeedback = null;

            public function generate(LlmRequest $request): LlmResponse
            {
                $this->calls++;

                if (str_contains($request->systemPrompt, 'query intent')) {
                    if ($this->calls === 1) {
                        return new LlmResponse(
                            '{"select":["case_records.missing"]}',
                            'test',
                            'synthetic-model',
                        );
                    }

                    $this->retryFeedback = $request->userMessage;
                    return new LlmResponse(
                        '{"select":["case_records.state"]}',
                        'test',
                        'synthetic-model',
                    );
                }

                return new LlmResponse(
                    'The matching record has state QLD.',
                    'test',
                    'synthetic-model',
                );
            }
        };

        $result = $this->agent($schema, $llm)->ask('Show the state');

        self::assertSame(3, $llm->calls);
        self::assertNotNull($llm->retryFeedback);
        self::assertStringContainsString(
            'Intent references a field that is not in the retrieved schema catalog.',
            $llm->retryFeedback,
        );
        self::assertSame(
            'The matching record has state QLD.',
            $result['answer'],
        );
    }

    public function testRejectsStaleKnowledgeBeforeCallingLlm(): void
    {
        $originalSchema = $this->schema();
        $this->saveSnapshot($originalSchema);

        $changedSchema = $originalSchema;
        $changedSchema['tables'][] = [
            'name' => 'new_records',
            'type' => 'BASE TABLE',
            'comment' => '',
        ];

        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                throw new RuntimeException(
                    'The LLM must not be called for stale schema.'
                );
            }
        };

        $agent = $this->agent($changedSchema, $llm);

        try {
            $agent->ask('Show case records and state');
            self::fail('Expected stale schema to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Rebuild the knowledge base',
                $exception->getMessage(),
            );
        }
    }

    public function testRejectsEmptyQuestionBeforeDatabaseDiscovery(): void
    {
        $adapter = new class implements SchemaDiscoveryAdapter {
            public function discover(): array
            {
                throw new RuntimeException(
                    'Schema discovery should not be called.'
                );
            }
        };

        $llm = new class implements LlmAdapter {
            public function generate(LlmRequest $request): LlmResponse
            {
                throw new RuntimeException(
                    'The LLM should not be called.'
                );
            }
        };

        $loader = new KnowledgeBaseLoader(
            new KnowledgeBaseStore($this->knowledgePath),
        );

        $agent = new DatabaseQuestionAgent(
            new SchemaContextRetriever(knowledgeBaseLoader: $loader),
            new SchemaDiscoveryService($adapter),
            $loader,
            new QueryIntentGenerator($llm),
            new QueryIntentPlanBuilder(),
            new ReadOnlyQueryExecutor($this->pdo),
            new EvidenceAnswerGenerator($llm),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The database question cannot be empty.'
        );

        $agent->ask('   ');
    }

    /**
     * @param array<string, mixed> $currentSchema
     */
    private function agent(
        array $currentSchema,
        LlmAdapter $llm,
    ): DatabaseQuestionAgent {
        $adapter = new class($currentSchema) implements SchemaDiscoveryAdapter {
            public function __construct(
                private readonly array $schema,
            ) {
            }

            public function discover(): array
            {
                return $this->schema;
            }
        };

        $loader = new KnowledgeBaseLoader(
            new KnowledgeBaseStore($this->knowledgePath),
        );

        return new DatabaseQuestionAgent(
            new SchemaContextRetriever(knowledgeBaseLoader: $loader),
            new SchemaDiscoveryService($adapter),
            $loader,
            new QueryIntentGenerator($llm),
            new QueryIntentPlanBuilder(),
            new ReadOnlyQueryExecutor($this->pdo),
            new EvidenceAnswerGenerator($llm),
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function saveSnapshot(array $schema): void
    {
        $fingerprint = new SchemaFingerprint();

        (new KnowledgeBaseStore($this->knowledgePath))->save([
            'format_version' => 2,
            'generated_at' => gmdate('c'),
            'source' => [
                'database' => $schema['database'],
                'schema_fingerprint' => $fingerprint->calculate($schema),
            ],
            'schema_summary' => [
                'table_count' => count($schema['tables']),
                'column_count' => count($schema['columns']),
                'declared_foreign_key_count' => count($schema['foreign_keys']),
            ],
            'profiling_config' => [
                'sample_limit' => 100,
                'max_declared_to_profile' => 5,
                'max_inferred_to_profile' => 20,
            ],
            'schema' => $schema,
            'relationships' => [
                'declared_relationships' => [],
                'inferred_candidates' => [],
            ],
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $entryPath = $entry->getPathname();

            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entryPath);
            } else {
                unlink($entryPath);
            }
        }

        rmdir($directory);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'database' => 'synthetic_test_database',
            'tables' => [
                [
                    'name' => 'case_records',
                    'type' => 'BASE TABLE',
                    'comment' => 'Synthetic test records',
                ],
            ],
            'columns' => [
                [
                    'table_name' => 'case_records',
                    'name' => 'id',
                    'ordinal_position' => 1,
                    'data_type' => 'integer',
                    'native_type' => 'INTEGER',
                    'nullable' => false,
                    'default' => null,
                    'is_primary_key' => true,
                    'is_indexed' => true,
                    'is_unique' => true,
                    'auto_increment' => false,
                    'comment' => '',
                ],
                [
                    'table_name' => 'case_records',
                    'name' => 'state',
                    'ordinal_position' => 2,
                    'data_type' => 'text',
                    'native_type' => 'TEXT',
                    'nullable' => true,
                    'default' => null,
                    'is_primary_key' => false,
                    'is_indexed' => false,
                    'is_unique' => false,
                    'auto_increment' => false,
                    'comment' => '',
                ],
            ],
            'foreign_keys' => [],
        ];
    }
}
