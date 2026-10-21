<?php
declare(strict_types=1);

namespace CzAiAgent\Agent;

use CzAiAgent\Database\KnowledgeBaseLoader;
use CzAiAgent\Database\ReadOnlyQueryExecutor;
use CzAiAgent\Database\SchemaContextRetriever;
use CzAiAgent\Database\SchemaDiscoveryService;
use InvalidArgumentException;
use RuntimeException;

final class DatabaseQuestionAgent
{
    public function __construct(
        private readonly SchemaContextRetriever $schemaContextRetriever,
        private readonly SchemaDiscoveryService $schemaDiscovery,
        private readonly KnowledgeBaseLoader $knowledgeBaseLoader,
        private readonly QueryPlanGenerator $queryPlanGenerator,
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

        // Verify the saved knowledge against the current database structure.
        $currentSchema = $this->schemaDiscovery->discover();
        $knowledge = $this->knowledgeBaseLoader->load($currentSchema);

        if (($knowledge['schema_status'] ?? null) !== 'current') {
            throw new RuntimeException(
                'The database schema has changed or could not be verified. '
                . 'Rebuild the knowledge base before querying live records.'
            );
        }

        $this->schemaContextRetriever->reloadKnowledge();

        $snapshot = $knowledge['snapshot'] ?? null;
        $fullSchema = $snapshot['schema'] ?? null;

        if (!is_array($fullSchema)) {
            throw new RuntimeException(
                'The knowledge base does not contain a valid schema.'
            );
        }

        // Give the planner a relevant subset, not the entire database schema.
        $schemaContext = $this->schemaContextRetriever->retrieve($question);

        // The LLM proposes a plan; the compiler independently validates it.
        $plan = $this->queryPlanGenerator->generatePlan(
            $question,
            $schemaContext,
        );

        // Execute only after validating against the complete trusted schema.
        $queryResult = $this->queryExecutor->execute(
            $plan,
            $fullSchema,
        );

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

        $answer = $this->answerGenerator->generateAnswer(
            $question,
            $queryResult,
        );

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
        ];
    }
}
