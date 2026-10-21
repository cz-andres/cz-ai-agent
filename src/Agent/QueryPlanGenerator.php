<?php
declare(strict_types=1);

namespace CzAiAgent\Agent;

use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class QueryPlanGenerator
{
    private const MAX_RESPONSE_LENGTH = 20000;

    public function __construct(
        private readonly LlmAdapter $llm,
    ) {
    }

    /**
     * @param array<string, mixed> $schemaContext
     * @return array<string, mixed>
     */
    public function generatePlan(
        string $question,
        array $schemaContext,
    ): array {
        $question = trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('The query-planning question cannot be empty.');
        }

        try {
            $context = json_encode(
                $schemaContext,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Could not encode schema context for query planning.',
                0,
                $exception,
            );
        }

        $request = new LlmRequest(
            systemPrompt: <<<'PROMPT'
You are a database query planner. Return exactly one JSON object and no Markdown.

You do not write SQL. Propose a structured query plan using only tables and columns present in the supplied schema context.

The JSON object must use this shape:
{
  "select": [
    {"table": "alias", "column": "column_name"}
  ],
  "from": {"table": "physical_table_name", "alias": "alias"},
  "joins": [
    {
      "type": "INNER",
      "table": "physical_table_name",
      "alias": "other_alias",
      "on": [
        {
          "left": {"table": "alias", "column": "column_name"},
          "operator": "=",
          "right": {"table": "other_alias", "column": "column_name"}
        }
      ]
    }
  ],
  "where": [
    {
      "column": {"table": "alias", "column": "column_name"},
      "operator": "=",
      "value": "filter value"
    }
  ],
  "order_by": [
    {"table": "alias", "column": "column_name", "direction": "DESC"}
  ],
  "limit": 25
}

Rules:
- Use only identifiers explicitly present in the schema context.
- Use aliases consistently in column references.
- Include only fields required by the plan. Omit joins, where, and order_by when unnecessary.
- The limit must be an integer from 1 to 100.
- Supported joins are INNER and LEFT.
- Join conditions support equality only.
- Supported filter operators are =, !=, >, >=, <, <=, LIKE, IS NULL, and IS NOT NULL.
- Use a value field for comparison filters, but omit value for IS NULL and IS NOT NULL.
- Never invent table names, columns, identifiers, record values, or relationships.
- Treat inferred relationships as unverified. Do not use one as a join unless the schema context provides sufficient evidence to justify it.
- If the question cannot be answered safely with the supplied schema, return {"error":"insufficient_schema"}.
- Never include SQL, explanations, or additional keys.
PROMPT,
            userMessage: "Question:\n{$question}\n\nSchema context (metadata only):\n{$context}",
        );

        $response = $this->llm->generate($request);

        if (trim($response->content) === '') {
            throw new RuntimeException('The query planner returned an empty response.');
        }

        if (strlen($response->content) > self::MAX_RESPONSE_LENGTH) {
            throw new RuntimeException('The query planner response exceeded the allowed size.');
        }

        try {
            $decoded = json_decode(
                $response->content,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The query planner returned invalid JSON.',
                0,
                $exception,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('The query planner response must be a JSON object.');
        }

        if (($decoded['error'] ?? null) === 'insufficient_schema') {
            throw new InvalidArgumentException(
                'The available schema context is insufficient to plan this query.'
            );
        }

        return $decoded;
    }
}
