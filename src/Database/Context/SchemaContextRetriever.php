<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Context;

use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use CzAiAgent\Database\Knowledge\KnowledgeBaseLoader;
use InvalidArgumentException;

final class SchemaContextRetriever
{
    private ?array $knowledge = null;
    private readonly InsuranceSchemaGlossary $glossary;

    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'at', 'be', 'by', 'can', 'do', 'for',
        'from', 'get', 'give', 'how', 'i', 'in', 'is', 'it', 'me', 'of',
        'on', 'or', 'our', 'please', 'show', 'tell', 'that', 'the',
        'their', 'this', 'to', 'was', 'what', 'when', 'where', 'which',
        'who', 'with', 'would', 'you',
    ];

    public function __construct(
        private readonly ?SchemaDiscoveryService $discovery = null,
        private readonly ?KnowledgeBaseLoader $knowledgeBaseLoader = null,
        ?InsuranceSchemaGlossary $glossary = null,
    ) {
        if ($discovery === null && $knowledgeBaseLoader === null) {
            throw new InvalidArgumentException(
                'Schema discovery or a knowledge-base loader is required.'
            );
        }

        $this->glossary = $glossary ?? new InsuranceSchemaGlossary();
    }

    public function useVerifiedSnapshot(array $snapshot): void
    {
        $schema = $snapshot['schema'] ?? null;
        $relationships = $snapshot['relationships'] ?? null;

        if (
            !is_array($schema)
            || !is_array($relationships)
            || !isset($schema['database'], $schema['tables'], $schema['columns'])
            || !is_array($schema['tables'])
            || !is_array($schema['columns'])
            || !isset(
                $relationships['declared_relationships'],
                $relationships['inferred_candidates'],
            )
            || !is_array($relationships['declared_relationships'])
            || !is_array($relationships['inferred_candidates'])
        ) {
            throw new InvalidArgumentException(
                'The verified knowledge snapshot is malformed.'
            );
        }

        $this->knowledge = [
            'schema' => $schema,
            'relationships' => $relationships,
        ];
    }

    public function reloadKnowledge(): void
    {
        $this->knowledge = $this->loadKnowledge();
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(
        string $question,
        int $maxTables = 8,
        int $maxColumnsPerTable = 12,
    ): array {
        if (trim($question) === '') {
            throw new InvalidArgumentException(
                'The schema context question cannot be empty.'
            );
        }

        if ($maxTables < 1 || $maxTables > 30) {
            throw new InvalidArgumentException(
                'Maximum tables must be between 1 and 30.'
            );
        }

        if ($maxColumnsPerTable < 1 || $maxColumnsPerTable > 50) {
            throw new InvalidArgumentException(
                'Maximum columns per table must be between 1 and 50.'
            );
        }

        $knowledge = $this->knowledge ??= $this->loadKnowledge();
        $schema = $knowledge['schema'];
        $questionTokens = $this->tokens($question);
        $glossaryMatches = $this->glossary->matchedColumnNames($question);
        $questionConcepts = $this->glossary->matchedConcepts($question);

        $columnsByTable = [];
        foreach ($schema['columns'] as $column) {
            if (isset($column['table_name'], $column['name'])) {
                $columnsByTable[$column['table_name']][] = $column;
            }
        }

        $tableMetadata = [];
        foreach ($schema['tables'] as $table) {
            if (isset($table['name'])) {
                $tableMetadata[$table['name']] = $table;
            }
        }

        $ranked = [];
        foreach ($tableMetadata as $name => $table) {
            $columns = $columnsByTable[$name] ?? [];
            $score = 0;
            $matchedTokens = 0;
            $exactMatchedColumns = [];

            foreach ($columns as $column) {
                if ($this->isExactColumnMention($question, (string) $column['name'])) {
                    $exactMatchedColumns[] = $column['name'];
                }

                $semantics = $this->glossary->fieldSemantics(
                    $name,
                    (string) $column['name'],
                    $schema,
                );
                if (array_intersect($questionConcepts, $semantics['canonical_concepts']) !== []) {
                    $exactMatchedColumns[] = $column['name'];
                }
            }

            $glossaryMatchedColumns = array_values(array_intersect(
                array_keys($glossaryMatches),
                array_column($columns, 'name'),
            ));
            $score += count($exactMatchedColumns) * 20
                + count($glossaryMatchedColumns) * 12;
            $matchedColumns = array_values(array_unique([
                ...$exactMatchedColumns,
                ...$glossaryMatchedColumns,
            ]));

            foreach ($questionTokens as $token) {
                $best = max(
                    $this->tokenScore($token, $name) * 2,
                    $this->tokenScore($token, (string) ($table['comment'] ?? '')),
                );

                foreach ($columns as $column) {
                    $columnScore = $this->tokenScore(
                        $token,
                        (string) $column['name'] . ' '
                        . (string) ($column['comment'] ?? '')
                    );

                    $columnScore = $columnScore > 0
                        ? $columnScore + 1
                        : 0;

                    $best = max($best, $columnScore);
                }

                if ($best > 0) {
                    $matchedTokens++;
                    $score += $best;
                }
            }

            if ($score > 0) {
                $ranked[$name] = [
                    'metadata' => $table,
                    'columns' => $columns,
                    'score' => $score,
                    'matched_tokens' => $matchedTokens,
                    'exact_matched_columns' => $exactMatchedColumns,
                    'matched_columns' => $matchedColumns,
                    'selection_reason' => 'question_match',
                ];
            }
        }

        uasort(
            $ranked,
            static fn (array $a, array $b): int =>
            ($b['score'] <=> $a['score'])
                ?: ($b['matched_tokens'] <=> $a['matched_tokens'])
        );

        $matchedTableCount = count($ranked);
        $selected = array_slice($ranked, 0, $maxTables, true);
        $initialSelectedNames = array_fill_keys(array_keys($selected), true);

        $declaredRelationships = $knowledge['relationships']['declared_relationships'];
        $initialNames = array_keys($initialSelectedNames);
        $bridgeScores = [];

        for ($i = 0, $count = count($initialNames); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $path = $this->declaredRelationshipPath(
                    $initialNames[$i],
                    $initialNames[$j],
                    $declaredRelationships,
                );

                foreach (array_slice($path, 1, -1) as $bridgeTable) {
                    $bridgeScores[$bridgeTable] = ($bridgeScores[$bridgeTable] ?? 0) + 1;
                }
            }
        }

        arsort($bridgeScores);
        foreach ($bridgeScores as $name => $score) {
            if (count($selected) >= $maxTables) {
                break;
            }

            if (isset($tableMetadata[$name])) {
                $selected[$name] = [
                    'metadata' => $tableMetadata[$name],
                    'columns' => $columnsByTable[$name] ?? [],
                    'score' => 0,
                    'matched_tokens' => 0,
                    'exact_matched_columns' => [],
                    'selection_reason' => 'declared_relationship_bridge',
                ];
            }
        }

        $neighborScores = [];

        // Expand only one hop from the initially relevant tables. Declared
        // foreign keys are trusted for expansion; inferred links are not.
        foreach ($declaredRelationships as $relationship) {
            $source = $relationship['source_table'] ?? null;
            $target = $relationship['target_table'] ?? null;

            if (!is_string($source) || !is_string($target)) {
                continue;
            }

            if (
                isset($initialSelectedNames[$source])
                && !isset($initialSelectedNames[$target])
                && isset($tableMetadata[$target])
            ) {
                $neighborScores[$target] = ($neighborScores[$target] ?? 0) + 1;
            }

            if (
                isset($initialSelectedNames[$target])
                && !isset($initialSelectedNames[$source])
                && isset($tableMetadata[$source])
            ) {
                $neighborScores[$source] = ($neighborScores[$source] ?? 0) + 1;
            }
        }

        arsort($neighborScores);

        foreach ($neighborScores as $name => $relationshipCount) {
            if (count($selected) >= $maxTables) {
                break;
            }

            $selected[$name] = [
                'metadata' => $tableMetadata[$name],
                'columns' => $columnsByTable[$name] ?? [],
                'score' => 0,
                'matched_tokens' => 0,
                'selection_reason' => 'declared_relationship_neighbour',
            ];
        }

        $selectedNames = array_fill_keys(array_keys($selected), true);

        $selectedDeclared = array_values(array_filter(
            $declaredRelationships,
            static fn (array $relationship): bool =>
                isset($selectedNames[$relationship['source_table'] ?? ''])
                && isset($selectedNames[$relationship['target_table'] ?? ''])
        ));

        $inferredCandidates = array_values(array_filter(
            $knowledge['relationships']['inferred_candidates'],
            static fn (array $relationship): bool =>
                isset($selectedNames[$relationship['source_table'] ?? ''])
                && isset($selectedNames[$relationship['target_table'] ?? ''])
        ));

        usort(
            $inferredCandidates,
            static fn (array $a, array $b): int =>
                ($b['confidence']['evidence_score'] ?? 0)
                <=> ($a['confidence']['evidence_score'] ?? 0)
        );

        $tables = [];
        $fieldCandidates = [];
        $tableContextByName = [];
        foreach ($selected as $name => $entry) {
            $columns = $entry['columns'];

            usort(
                $columns,
                function (array $a, array $b) use ($questionTokens, $question): int {
                    $aScore = $this->columnScore($a, $questionTokens, $question);
                    $bScore = $this->columnScore($b, $questionTokens, $question);

                    return $bScore <=> $aScore;
                }
            );

            $chosenColumns = [];
            foreach (array_slice($columns, 0, $maxColumnsPerTable) as $column) {
                $chosenColumns[$column['name']] = $column;
            }

            foreach ($entry['matched_columns'] ?? [] as $columnName) {
                $this->includeColumn($chosenColumns, $columns, $columnName);
            }

            // Never omit a declared join key just because the per-table
            // column limit was reached.
            foreach ($selectedDeclared as $relationship) {
                if (($relationship['source_table'] ?? null) === $name) {
                    $this->includeColumn(
                        $chosenColumns,
                        $columns,
                        (string) ($relationship['source_column'] ?? '')
                    );
                }

                if (($relationship['target_table'] ?? null) === $name) {
                    $this->includeColumn(
                        $chosenColumns,
                        $columns,
                        (string) ($relationship['target_column'] ?? '')
                    );
                }
            }

            // Show inferred join columns as candidates, not verified facts.
            foreach ($inferredCandidates as $relationship) {
                if (($relationship['source_table'] ?? null) === $name) {
                    $this->includeColumn(
                        $chosenColumns,
                        $columns,
                        (string) ($relationship['source_column'] ?? '')
                    );
                }

                if (($relationship['target_table'] ?? null) === $name) {
                    $this->includeColumn(
                        $chosenColumns,
                        $columns,
                        (string) ($relationship['target_column'] ?? '')
                    );
                }
            }

            foreach ($chosenColumns as $columnName => &$column) {
                $column['exact_question_match'] = in_array(
                    $columnName,
                    $entry['exact_matched_columns'] ?? [],
                    true,
                );
                $candidateId = $name . '.' . $columnName;
                $candidate = [
                    'id' => $candidateId,
                    'table' => $name,
                    'column' => $columnName,
                    'table_comment' => $entry['metadata']['comment'] ?? '',
                    'selection_reason' => $entry['selection_reason'],
                    'data_type' => $column['data_type'] ?? null,
                    'comment' => $column['comment'] ?? '',
                    'exact_question_match' => $column['exact_question_match'],
                    'matched_glossary_terms' => $glossaryMatches[$columnName] ?? [],
                    ...$this->glossary->fieldSemantics($name, $columnName, $schema),
                ];
                $fieldCandidates[$candidateId] = $candidate;
            }
            unset($column);

            $tables[] = [
                'name' => $name,
                'comment' => $entry['metadata']['comment'] ?? '',
                'relevance_score' => $entry['score'],
                'selection_reason' => $entry['selection_reason'],
                'columns' => array_values($chosenColumns),
            ];
            $tableContextByName[$name] = end($tables);
        }

        foreach ($schema['columns'] as $column) {
            $tableName = $column['table_name'] ?? null;
            $columnName = $column['name'] ?? null;
            if (!is_string($tableName) || !is_string($columnName)) {
                continue;
            }

            $semantics = $this->glossary->fieldSemantics(
                $tableName,
                $columnName,
                $schema,
            );
            $isMatchedColumn = $this->isExactColumnMention($question, $columnName)
                || isset($glossaryMatches[$columnName]);
            $isCanonicalConcept = array_intersect(
                $questionConcepts,
                $semantics['canonical_concepts'],
            ) !== [];
            if (!$isMatchedColumn && !$isCanonicalConcept) {
                continue;
            }

            $candidateId = $tableName . '.' . $columnName;
            $candidate = [
                'id' => $candidateId,
                'table' => $tableName,
                'column' => $columnName,
                'table_comment' => $tableMetadata[$tableName]['comment'] ?? '',
                'selection_reason' => $tableContextByName[$tableName]['selection_reason'] ?? 'exact_field_match',
                'data_type' => $column['data_type'] ?? null,
                'comment' => $column['comment'] ?? '',
                'exact_question_match' => $this->isExactColumnMention($question, $columnName),
                'matched_glossary_terms' => $glossaryMatches[$columnName] ?? [],
                ...$semantics,
            ];
            $fieldCandidates[$candidateId] = $candidate;
        }

        $candidateTableNames = array_fill_keys(
            array_column(array_values($fieldCandidates), 'table'),
            true,
        );
        $candidateRelationships = array_values(array_filter(
            $declaredRelationships,
            static fn (array $relationship): bool =>
                isset($candidateTableNames[$relationship['source_table'] ?? ''])
                && isset($candidateTableNames[$relationship['target_table'] ?? ''])
        ));

        $bestScore = $ranked === [] ? 0 : reset($ranked)['score'];
        $matchedQuestionTokenCount = $ranked === []
            ? 0
            : reset($ranked)['matched_tokens'];

        $confidence = match (true) {
            $bestScore >= 9 && $matchedQuestionTokenCount >= 2 => 'high',
            $bestScore >= 3 => 'medium',
            default => 'low',
        };

        $warnings = [];
        if ($questionTokens === []) {
            $warnings[] = 'The question did not contain enough searchable terms.';
        } elseif ($matchedTableCount === 0) {
            $warnings[] = 'No schema tables or columns matched the question terms.';
        } elseif ($confidence === 'low') {
            $warnings[] = 'Schema retrieval confidence is low; verify the intended tables before querying.';
        }

        if ($matchedTableCount > $maxTables) {
            $warnings[] = 'The table limit excluded some matching tables.';
        }

        return [
            'database' => $schema['database'],
            'tables' => $tables,
            'field_candidates' => array_values($fieldCandidates),
            'relationships' => $selectedDeclared,
            'candidate_relationships' => $candidateRelationships,
            'inferred_relationship_candidates' => array_slice(
                $inferredCandidates,
                0,
                12
            ),
            'matched_table_count' => $matchedTableCount,
            'matched_question_token_count' => $matchedQuestionTokenCount,
            'retrieval_confidence' => $confidence,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $column
     * @param list<string> $questionTokens
     */
    private function columnScore(
        array $column,
        array $questionTokens,
        string $question,
    ): int
    {
        $score = $this->isExactColumnMention(
            $question,
            (string) ($column['name'] ?? ''),
        ) ? 100 : 0;

        foreach ($questionTokens as $token) {
            $match = $this->tokenScore(
                $token,
                (string) $column['name'] . ' '
                . (string) ($column['comment'] ?? '')
            );

            if ($match > 0) {
                $score += $match + 1;
            }
        }

        if ($column['is_primary_key'] ?? false) {
            $score += 3;
        }

        if ($column['is_indexed'] ?? false) {
            $score += 1;
        }

        if (
            preg_match('/\b(latest|last|newest|most recent)\b/i', $question) === 1
            && preg_match(
                '/date|time|created|updated|effective|expiry|start|end|version|sequence/i',
                (string) ($column['name'] ?? '')
                    . ' ' . (string) ($column['data_type'] ?? '')
                    . ' ' . (string) ($column['comment'] ?? ''),
            ) === 1
        ) {
            $score += 30;
        }

        return $score;
    }

    private function isExactColumnMention(string $question, string $columnName): bool
    {
        return preg_match(
            '/(?<![A-Za-z0-9_$])' . preg_quote($columnName, '/') . '(?![A-Za-z0-9_$])/i',
            $question,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $columns
     * @param list<array<string, mixed>> $allColumns
     */
    private function includeColumn(
        array &$columns,
        array $allColumns,
        string $columnName,
    ): void {
        if ($columnName === '' || isset($columns[$columnName])) {
            return;
        }

        foreach ($allColumns as $column) {
            if (($column['name'] ?? null) === $columnName) {
                $columns[$columnName] = $column;
                return;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $relationships
     * @return list<string>
     */
    private function declaredRelationshipPath(
        string $start,
        string $target,
        array $relationships,
    ): array {
        $adjacency = [];
        foreach ($relationships as $relationship) {
            if (
                !is_array($relationship)
                || !is_string($relationship['source_table'] ?? null)
                || !is_string($relationship['target_table'] ?? null)
            ) {
                continue;
            }

            $source = $relationship['source_table'];
            $destination = $relationship['target_table'];
            $adjacency[$source][] = $destination;
            $adjacency[$destination][] = $source;
        }

        $queue = [[$start]];
        $visited = [$start => true];

        while ($queue !== []) {
            $path = array_shift($queue);
            $current = $path[array_key_last($path)];

            if ($current === $target) {
                return $path;
            }

            foreach ($adjacency[$current] ?? [] as $neighbor) {
                if (isset($visited[$neighbor])) {
                    continue;
                }

                $visited[$neighbor] = true;
                $queue[] = [...$path, $neighbor];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        // Split camelCase before lowercasing; underscores and punctuation
        // then become token boundaries.
        $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text) ?? $text;
        $text = strtolower(str_replace('_', ' ', $text));
        preg_match_all('/[a-z0-9]+/', $text, $matches);

        $tokens = [];
        foreach ($matches[0] as $token) {
            if (strlen($token) < 2 || in_array($token, self::STOP_WORDS, true)) {
                continue;
            }

            $tokens[] = $token;

            // Generic singular/plural normalisation for common English names.
            if (strlen($token) > 4 && str_ends_with($token, 'ies')) {
                $tokens[] = substr($token, 0, -3) . 'y';
            } elseif (
                strlen($token) > 3
                && str_ends_with($token, 's')
                && !str_ends_with($token, 'ss')
            ) {
                $tokens[] = substr($token, 0, -1);
            }
        }

        return array_values(array_unique($tokens));
    }

    private function tokenScore(string $questionToken, string $schemaText): int
    {
        $schemaTokens = $this->tokens($schemaText);
        $best = 0;

        foreach ($schemaTokens as $schemaToken) {
            if ($questionToken === $schemaToken) {
                $best = max($best, 3);
            } elseif (
                strlen($questionToken) >= 4
                && strlen($schemaToken) >= 4
                && (
                    str_contains($schemaToken, $questionToken)
                    || str_contains($questionToken, $schemaToken)
                )
            ) {
                $best = max($best, 1);
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadKnowledge(): array
    {
        if ($this->knowledgeBaseLoader !== null) {
            $snapshot = $this->knowledgeBaseLoader->load()['snapshot'];

            return [
                'schema' => $snapshot['schema'],
                'relationships' => $snapshot['relationships'],
            ];
        }

        if ($this->discovery === null) {
            throw new InvalidArgumentException(
                'Schema discovery is unavailable.'
            );
        }

        $schema = $this->discovery->discover();

        return [
            'schema' => $schema,
            'relationships' => [
                'declared_relationships' => $schema['foreign_keys'] ?? [],
                'inferred_candidates' => [],
            ],
        ];
    }
}
