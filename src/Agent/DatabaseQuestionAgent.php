<?php
declare(strict_types=1);

namespace CzAiAgent\Agent;

use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use CzAiAgent\Database\Query\QueryIntentPlanBuilder;
use CzAiAgent\Database\Query\ReadOnlyQueryExecutor;
use CzAiAgent\Database\Context\SchemaContextRetriever;
use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use InvalidArgumentException;
use RuntimeException;

final class DatabaseQuestionAgent
{
    public function __construct(
        private readonly SchemaContextRetriever $schemaContextRetriever,
        private readonly SchemaDiscoveryService $schemaDiscovery,
        private readonly KnowledgeBaseLoader $knowledgeBaseLoader,
        private readonly QueryIntentGenerator $queryIntentGenerator,
        private readonly QueryIntentPlanBuilder $queryIntentPlanBuilder,
        private readonly ReadOnlyQueryExecutor $queryExecutor,
        private readonly EvidenceAnswerGenerator $answerGenerator,
    ) {
    }

    /**
     * @return array{
     *     answer: string,
     *     provider: string,
     *     model: string,
     *     evidence: array{
     *         source: string,
     *         tables: list<string>,
     *         returned_rows: int
     *     },
     *     diagnostics: array{
     *         retrieval_confidence: string,
     *         matched_table_count: int,
     *         candidate_field_count: int,
     *         join_count: int,
     *         timings_ms: array<string, float>
     *     }
     * }
     */
    public function ask(string $question): array
    {
        $question = trim($question);

        if ($question === '') {
            throw new InvalidArgumentException(
                'The database question cannot be empty.'
            );
        }

        $totalStartedAt = hrtime(true);

        // Verify the saved knowledge against the current database structure.
        $stageStartedAt = hrtime(true);
        $currentSchema = $this->schemaDiscovery->discover();
        $schemaDiscoveryMs = $this->elapsedMilliseconds($stageStartedAt);

        $stageStartedAt = hrtime(true);
        $knowledge = $this->knowledgeBaseLoader->load($currentSchema);
        $knowledgeValidationMs = $this->elapsedMilliseconds($stageStartedAt);

        if (($knowledge['schema_status'] ?? null) !== 'current') {
            throw new StaleSchemaException(
                'The database schema has changed or could not be verified. '
                . 'Rebuild the knowledge base before querying live records.'
            );
        }

        $snapshot = $knowledge['snapshot'] ?? null;

        if (!is_array($snapshot)) {
            throw new RuntimeException(
                'The knowledge base did not return a valid snapshot.'
            );
        }

        $this->schemaContextRetriever->useVerifiedSnapshot($snapshot);

        $fullSchema = $snapshot['schema'] ?? null;

        if (!is_array($fullSchema)) {
            throw new RuntimeException(
                'The knowledge base does not contain a valid schema.'
            );
        }

        // Give the planner a relevant subset, not the entire database schema.
        $stageStartedAt = hrtime(true);
        $schemaContext = $this->schemaContextRetriever->retrieve($question);
        $contextRetrievalMs = $this->elapsedMilliseconds($stageStartedAt);

        $stageStartedAt = hrtime(true);
        try {
            $intent = $this->queryIntentGenerator->generateIntent(
                $question,
                $schemaContext,
            );
            $plan = $this->queryIntentPlanBuilder->build(
                $intent,
                $schemaContext,
                $fullSchema,
            );
        } catch (InvalidArgumentException | InvalidQueryIntentException $exception) {
            $intent = $this->queryIntentGenerator->generateIntent(
                $question,
                $schemaContext,
                ['validation_error' => $exception->getMessage()],
            );

            try {
                $plan = $this->queryIntentPlanBuilder->build(
                    $intent,
                    $schemaContext,
                    $fullSchema,
                );
            } catch (InvalidArgumentException | InvalidQueryIntentException $retryException) {
                throw new RuntimeException(
                    'The query intent could not be mapped safely after one correction attempt: '
                    . $retryException->getMessage(),
                    0,
                    $retryException,
                );
            }
        }
        $planningMs = $this->elapsedMilliseconds($stageStartedAt);

        // Execute only after validating against the complete trusted schema.
        $stageStartedAt = hrtime(true);
        $queryResult = $this->queryExecutor->execute(
            $plan,
            $fullSchema,
        );
        $queryExecutionMs = $this->elapsedMilliseconds($stageStartedAt);

        $evidence = $queryResult['evidence'] ?? null;

        if (
            !is_array($evidence)
            || !is_string($evidence['source'] ?? null)
            || !is_array($evidence['tables'] ?? null)
            || !is_int($evidence['returned_rows'] ?? null)
        ) {
            throw new RuntimeException(
                'The database executor returned invalid evidence metadata.'
            );
        }

        $stageStartedAt = hrtime(true);
        $answer = $this->answerGenerator->generateAnswer(
            $question,
            $queryResult,
        );
        $answerGenerationMs = $this->elapsedMilliseconds($stageStartedAt);

        // Return the answer and provenance, not the raw records.
        return [
            'answer' => trim($answer->content),
            'provider' => $answer->provider,
            'model' => $answer->model,
            'evidence' => [
                'source' => $evidence['source'],
                'tables' => array_values($evidence['tables']),
                'returned_rows' => $evidence['returned_rows'],
            ],
            'diagnostics' => [
                'retrieval_confidence' => $schemaContext['retrieval_confidence'],
                'matched_table_count' => $schemaContext['matched_table_count'],
                'candidate_field_count' => count($schemaContext['field_candidates']),
                'join_count' => count($plan['joins'] ?? []),
                'timings_ms' => [
                    'schema_discovery' => $schemaDiscoveryMs,
                    'knowledge_validation' => $knowledgeValidationMs,
                    'context_retrieval' => $contextRetrievalMs,
                    'planning' => $planningMs,
                    'query_execution' => $queryExecutionMs,
                    'answer_generation' => $answerGenerationMs,
                    'total' => $this->elapsedMilliseconds($totalStartedAt),
                ],
            ],
        ];
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
