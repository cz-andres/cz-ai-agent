<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

use InvalidArgumentException;

final class SchemaContextRetriever
{
    private ?array $knowledge = null;

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
    ) {
        if ($discovery === null && $knowledgeBaseLoader === null) {
            throw new InvalidArgumentException(
                'Schema discovery or a knowledge-base loader is required.'
            );
        }
    }

    public function reloadKnowledge(): void
    {
        $this->knowledge = $this->loadKnowledge();
    }

    /**
     * @return array{
     *     database: string,
     *     tables: list<array{
     *         name: string,
     *         comment: string,
     *         relevance_score: int,
     *         columns: list<array<string, mixed>>
     *     }>,
     *     relationships: list<array<string, mixed>>,
     *     inferred_relationship_candidates: list<array<string, mixed>>,
     *     matched_table_count: int
     * }
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

        $columnsByTable = [];
        foreach ($schema['columns'] as $column) {
            $columnsByTable[$column['table_name']][] = $column;
        }

        $tableMetadata = [];
        foreach ($schema['tables'] as $table) {
            $tableMetadata[$table['name']] = $table;
        }

        $ranked = [];
        foreach ($schema['tables'] as $table) {
            $name = $table['name'];
            $columns = $columnsByTable[$name] ?? [];
            $score = $this->textScore(
                $questionTokens,
                $name . ' ' . ($table['comment'] ?? '')
            );

            foreach ($columns as $column) {
                $columnScore = $this->textScore(
                    $questionTokens,
                    $column['name'] . ' ' . ($column['comment'] ?? '')
                );

                $score += $columnScore;
            }

            if ($score > 0) {
                $ranked[$name] = [
                    'metadata' => $table,
                    'columns' => $columns,
                    'score' => $score,
                ];
            }
        }

        uasort(
            $ranked,
            static fn (array $a, array $b): int =>
                $b['score'] <=> $a['score']
        );

        $matchedTableCount = count($ranked);
        $selected = array_slice($ranked, 0, $maxTables, true);
        $selectedNames = array_fill_keys(array_keys($selected), true);
        $initialSelectedNames = $selectedNames;

        // Add directly related tables only when capacity remains.
        foreach ($schema['foreign_keys'] as $foreignKey) {
            $source = $foreignKey['source_table'];
            $target = $foreignKey['target_table'];

            if (isset($initialSelectedNames[$source]) && !isset($selectedNames[$target])) {
                if (count($selected) < $maxTables && isset($tableMetadata[$target])) {
                    $selected[$target] = [
                        'metadata' => $tableMetadata[$target],
                        'columns' => $columnsByTable[$target] ?? [],
                        'score' => 0,
                    ];
                    $selectedNames[$target] = true;
                }
            }

            if (isset($initialSelectedNames[$target]) && !isset($selectedNames[$source])) {
                if (count($selected) < $maxTables && isset($tableMetadata[$source])) {
                    $selected[$source] = [
                        'metadata' => $tableMetadata[$source],
                        'columns' => $columnsByTable[$source] ?? [],
                        'score' => 0,
                    ];
                    $selectedNames[$source] = true;
                }
            }
        }

        $tables = [];
        foreach ($selected as $name => $entry) {
            $columns = $entry['columns'];

            usort(
                $columns,
                function (array $a, array $b) use ($questionTokens): int {
                    $aScore = $this->textScore(
                        $questionTokens,
                        $a['name'] . ' ' . ($a['comment'] ?? '')
                    );
                    $bScore = $this->textScore(
                        $questionTokens,
                        $b['name'] . ' ' . ($b['comment'] ?? '')
                    );

                    // Keep keys and indexed columns available for query planning.
                    $aScore += ($a['is_primary_key'] ?? false) ? 3 : 0;
                    $bScore += ($b['is_primary_key'] ?? false) ? 3 : 0;
                    $aScore += ($a['is_indexed'] ?? false) ? 1 : 0;
                    $bScore += ($b['is_indexed'] ?? false) ? 1 : 0;

                    return $bScore <=> $aScore;
                }
            );

            $tables[] = [
                'name' => $name,
                'comment' => $entry['metadata']['comment'] ?? '',
                'relevance_score' => $entry['score'],
                'columns' => array_slice($columns, 0, $maxColumnsPerTable),
            ];
        }

        $declaredRelationships = array_values(array_filter(
            $knowledge['relationships']['declared_relationships'],
            static fn (array $relationship): bool =>
                isset($selectedNames[$relationship['source_table']])
                && isset($selectedNames[$relationship['target_table']])
        ));

        $inferredCandidates = array_values(array_filter(
            $knowledge['relationships']['inferred_candidates'],
            static fn (array $relationship): bool =>
                isset($selectedNames[$relationship['source_table']])
                && isset($selectedNames[$relationship['target_table']])
        ));

        usort(
            $inferredCandidates,
            static fn (array $a, array $b): int =>
                ($b['confidence']['evidence_score'] ?? 0)
                <=> ($a['confidence']['evidence_score'] ?? 0)
        );

        return [
            'database' => $schema['database'],
            'tables' => $tables,
            'relationships' => $declaredRelationships,
            'inferred_relationship_candidates' => array_slice(
                $inferredCandidates,
                0,
                12
            ),
            'matched_table_count' => $matchedTableCount,
        ];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        preg_match_all('/[a-z][a-z0-9_]*/i', strtolower($text), $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $token): bool =>
                strlen($token) > 1
                && !in_array($token, self::STOP_WORDS, true)
        )));
    }

    /**
     * @param list<string> $questionTokens
     */
    private function textScore(array $questionTokens, string $text): int
    {
        $schemaTokens = $this->tokens(
            str_replace('_', ' ', $text)
        );

        $score = 0;
        foreach ($questionTokens as $token) {
            foreach ($schemaTokens as $schemaToken) {
                if ($token === $schemaToken) {
                    $score += 3;
                } elseif (
                    strlen($token) >= 4
                    && strlen($schemaToken) >= 4
                    && (
                        str_contains($schemaToken, $token)
                        || str_contains($token, $schemaToken)
                    )
                ) {
                    $score += 1;
                }
            }
        }

        return $score;
    }

    /**
     * @return array{
     *     schema: array<string, mixed>,
     *     relationships: array{
     *         declared_relationships: list<array<string, mixed>>,
     *         inferred_candidates: list<array<string, mixed>>
     *     }
     * }
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
                'declared_relationships' => array_map(
                    static fn (array $foreignKey): array => [
                        ...$foreignKey,
                        'status' => 'declared_foreign_key',
                    ],
                    $schema['foreign_keys']
                ),
                'inferred_candidates' => [],
            ],
        ];
    }
}