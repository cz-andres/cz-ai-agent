<?php
declare(strict_types=1);

namespace CzAiAgent\Agent;

use CzAiAgent\Llm\LlmAdapter;
use CzAiAgent\Llm\LlmRequest;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class QueryIntentGenerator
{
    private const MAX_RESPONSE_LENGTH = 20000;

    public function __construct(
        private readonly LlmAdapter $llm,
        private readonly QuestionConstraintExtractor $constraintExtractor = new QuestionConstraintExtractor(),
    ) {
    }

    /**
     * @param array<string, mixed> $schemaContext
     * @param array<string, mixed>|null $feedback
     * @return array<string, mixed>
     */
    public function generateIntent(
        string $question,
        array $schemaContext,
        ?array $feedback = null,
    ): array {
        $question = trim($question);
        if ($question === '') {
            throw new InvalidArgumentException('The query-planning question cannot be empty.');
        }

        $requiredConstraints = $this->constraintExtractor->extract(
            $question,
            $schemaContext,
        );

        try {
            $context = json_encode(
                $schemaContext,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $constraintsJson = json_encode(
                $requiredConstraints,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $feedbackJson = $feedback === null
                ? ''
                : "\n\nPrevious intent validation feedback:\n"
                    . json_encode($feedback, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Could not encode query-planning context.',
                0,
                $exception,
            );
        }

        $request = new LlmRequest(
            systemPrompt: <<<'PROMPT'
You interpret a user's database question as query intent. Return one JSON object only.
Do not write SQL, table aliases, joins, or physical table names.

Use only field IDs listed in field_candidates. These IDs identify exact database columns, so choose the field whose owning table matches the requested meaning and filters.
The application has extracted any explicit entity identifiers deterministically. Include every required_entity_filter exactly as supplied; do not reinterpret its field or value.

Shape:
{
  "select": ["table_name.column_name"],
  "filters": [
    {"field": "table_name.column_name", "operator": "=", "value": 123}
  ],
  "order_by": [
    {"field": "table_name.column_name", "direction": "DESC"}
  ],
  "group_by": ["table_name.category"],
  "aggregations": [
    {"function": "COUNT", "field": "table_name.id", "alias": "record_count"}
  ],
  "limit": 25
}

Rules:
- Values mentioned by the user must be preserved exactly as filter values; never invent values.
- Use only fields in field_candidates and supported operators =, !=, >, >=, <, <=, LIKE, IS NULL, IS NOT NULL.
- Omit filters only when the user did not request or provide a filter.
- Omit order_by unless needed to answer the question. Interpret "latest"/"last" only using a candidate date or sequence field whose meaning is clear; otherwise return {"error":"clarification_required","question":"..."}.
- When the user asks for the current period, use the verified current_period_id concept; do not substitute the latest period unless the user asks for the latest.
- Use aggregations and group_by for explicit requests for totals, averages, counts, comparisons, or common patterns; use only COUNT, SUM, AVG, MIN, and MAX.
- For grouped summaries, return group_by fields and one or more aggregations. Do not claim patterns unsupported by the returned values.
- Omit limit when unspecified; the application applies a safe default.
- If multiple field candidates have materially different meanings and the question does not disambiguate them, return {"error":"clarification_required","question":"..."}.
- If the schema candidates do not support the requested answer, return {"error":"insufficient_schema"}.
- Never include SQL, joins, aliases, explanations, or additional keys.
PROMPT,
            userMessage: "Question:\n{$question}\n\nSchema field catalog:\n{$context}\n\nRequired entity filters:\n{$constraintsJson}{$feedbackJson}",
        );

        $response = $this->llm->generate($request);
        if (trim($response->content) === '') {
            throw new InvalidQueryIntentException(
                'The query planner returned an empty response.'
            );
        }

        if (strlen($response->content) > self::MAX_RESPONSE_LENGTH) {
            throw new InvalidQueryIntentException(
                'The query planner response exceeded the allowed size.'
            );
        }

        try {
            $intent = json_decode(
                $response->content,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidQueryIntentException(
                'The query planner returned invalid JSON.',
                0,
                $exception,
            );
        }

        if (!is_array($intent) || array_is_list($intent)) {
            throw new InvalidQueryIntentException(
                'The query intent must be a JSON object.'
            );
        }

        if (($intent['error'] ?? null) === 'insufficient_schema') {
            throw new InsufficientSchemaException(
                'The available schema context is insufficient to answer this question.'
            );
        }

        if (($intent['error'] ?? null) === 'clarification_required') {
            throw new ClarificationRequiredException(
                is_string($intent['question'] ?? null)
                    ? $intent['question']
                    : 'Please clarify which database field or period you mean.'
            );
        }

        $intent = $this->applyRequiredConstraints(
            $intent,
            $requiredConstraints,
            $schemaContext,
        );
        $intent = $this->applyCurrentPeriodAnchor(
            $question,
            $intent,
            $schemaContext,
        );
        $intent = $this->applyLatestResultLimit($question, $intent);
        $this->validateIntentSemantics($question, $intent, $schemaContext);

        return $intent;
    }

    /**
     * @param array<string, mixed> $intent
     * @param list<array{field: string, concept: string, operator: string, value: string}> $requiredConstraints
     * @param array<string, mixed> $schemaContext
     * @return array<string, mixed>
     */
    private function applyRequiredConstraints(
        array $intent,
        array $requiredConstraints,
        array $schemaContext,
    ): array {
        $filters = $intent['filters'] ?? [];
        if (!is_array($filters) || !array_is_list($filters)) {
            throw new InvalidArgumentException('Intent filters must be a list.');
        }

        $candidateById = [];
        foreach ($schemaContext['field_candidates'] ?? [] as $candidate) {
            if (is_array($candidate) && is_string($candidate['id'] ?? null)) {
                $candidateById[$candidate['id']] = $candidate;
            }
        }

        foreach ($requiredConstraints as $constraint) {
            $filters = array_values(array_filter(
                $filters,
                static function (mixed $filter) use ($candidateById, $constraint): bool {
                    if (
                        !is_array($filter)
                        || !is_string($filter['field'] ?? null)
                    ) {
                        return true;
                    }

                    $candidate = $candidateById[$filter['field']] ?? null;
                    $concepts = is_array($candidate)
                        && is_array($candidate['concepts'] ?? null)
                        ? $candidate['concepts']
                        : [];

                    return $filter['field'] !== $constraint['field']
                        && !in_array($constraint['concept'], $concepts, true);
                },
            ));

            $filters[] = [
                'field' => $constraint['field'],
                'operator' => $constraint['operator'],
                'value' => $constraint['value'],
            ];
        }

        if ($filters !== []) {
            $intent['filters'] = $filters;
        }

        return $intent;
    }

    /**
     * Keep "current period" grounded in the database's verified current-period
     * reference, even if the model selects only descriptive period fields.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $schemaContext
     * @return array<string, mixed>
     */
    private function applyCurrentPeriodAnchor(
        string $question,
        array $intent,
        array $schemaContext,
    ): array {
        if (preg_match('/\bcurrent\s+period\b/i', $question) !== 1) {
            return $intent;
        }

        $fields = [];
        foreach ($schemaContext['field_candidates'] ?? [] as $candidate) {
            if (
                is_array($candidate)
                && is_string($candidate['id'] ?? null)
                && is_array($candidate['canonical_concepts'] ?? null)
                && in_array(
                    'current_period_id',
                    $candidate['canonical_concepts'] ?? [],
                    true,
                )
            ) {
                $fields[] = $candidate['id'];
            }
        }

        if (count($fields) !== 1) {
            throw new ClarificationRequiredException(
                'The schema does not identify one verified current-period field.'
            );
        }

        $select = $intent['select'] ?? [];
        if (!is_array($select) || !array_is_list($select)) {
            throw new InvalidArgumentException('Intent select must be a list.');
        }

        if (!in_array($fields[0], $select, true)) {
            $select[] = $fields[0];
        }
        $intent['select'] = $select;

        return $intent;
    }

    /**
     * Latest/last questions are singular unless the user explicitly asks for
     * a count. The model's generic default must not turn them into a list.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function applyLatestResultLimit(string $question, array $intent): array
    {
        if (preg_match('/\b(latest|last|newest|most recent)\b/i', $question) !== 1) {
            return $intent;
        }

        $requestedCount = null;
        if (preg_match(
            '/\b(?:latest|last|newest|most recent)\s+(\d+)\b|\b(\d+)\s+(?:latest|last|newest|most recent)\b/i',
            $question,
            $matches,
        ) === 1) {
            $countText = $matches[1] !== '' ? $matches[1] : $matches[2];
            $requestedCount = filter_var($countText, FILTER_VALIDATE_INT);
            if ($requestedCount === false || $requestedCount < 1 || $requestedCount > 100) {
                throw new InvalidArgumentException(
                    'The requested latest-record count must be between 1 and 100.'
                );
            }
        }

        $intent['limit'] = $requestedCount ?? 1;

        return $intent;
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $schemaContext
     */
    private function validateIntentSemantics(
        string $question,
        array $intent,
        array $schemaContext,
    ): void {
        $candidateById = [];
        foreach ($schemaContext['field_candidates'] ?? [] as $candidate) {
            if (is_array($candidate) && is_string($candidate['id'] ?? null)) {
                $candidateById[$candidate['id']] = $candidate;
            }
        }

        if (preg_match('/\b(latest|last|newest|most recent)\b/i', $question)) {
            $orderBy = $intent['order_by'] ?? [];
            if (!is_array($orderBy) || $orderBy === []) {
                throw new InvalidArgumentException(
                    'Questions about the latest or last record require an explicit supported sort field.'
                );
            }

            $firstOrder = $orderBy[0] ?? null;
            $firstFieldId = is_array($firstOrder)
                ? ($firstOrder['field'] ?? null)
                : null;
            $firstCandidate = is_string($firstFieldId)
                ? ($candidateById[$firstFieldId] ?? null)
                : null;
            $description = is_array($firstCandidate)
                ? (string) ($firstCandidate['column'] ?? '')
                    . ' ' . (string) ($firstCandidate['data_type'] ?? '')
                    . ' ' . (string) ($firstCandidate['comment'] ?? '')
                : '';
            $firstOrderIsTemporal = preg_match(
                '/date|time|created|updated|effective|expiry|start|end|version|sequence/i',
                $description,
            ) === 1;
            $firstOrderIsDescending = is_array($firstOrder)
                && ($firstOrder['direction'] ?? null) === 'DESC';

            if (!$firstOrderIsTemporal || !$firstOrderIsDescending) {
                throw new InvalidArgumentException(
                    'The latest or last record must be ordered first by a supported date or sequence field in descending order.'
                );
            }
        }

        if (
            preg_match('/\b(patterns?|commonalities|commonality|most common|frequency distribution)\b/i', $question) === 1
            && (
                !is_array($intent['group_by'] ?? null)
                || $intent['group_by'] === []
                || !is_array($intent['aggregations'] ?? null)
                || $intent['aggregations'] === []
            )
        ) {
            throw new InvalidArgumentException(
                'Pattern and commonality questions require grouped fields and at least one aggregation.'
            );
        }
    }
}
