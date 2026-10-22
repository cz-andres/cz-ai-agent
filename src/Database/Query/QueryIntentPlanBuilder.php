<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Query;

use InvalidArgumentException;

final class QueryIntentPlanBuilder
{
    private const MAX_LIMIT = 100;
    private const DEFAULT_LIMIT = 25;
    private const MAX_JOINS = 8;

    public function __construct(
        private readonly QueryPlanValidator $validator = new QueryPlanValidator(),
        private readonly ReadOnlyQueryPlanCompiler $compiler = new ReadOnlyQueryPlanCompiler(),
    ) {
    }

    /**
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $schemaContext
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function build(
        array $intent,
        array $schemaContext,
        array $schema,
    ): array {
        $this->assertKeys($intent, [
            'select',
            'filters',
            'order_by',
            'group_by',
            'aggregations',
            'limit',
        ]);

        $candidates = $this->indexCandidates($schemaContext);
        $select = $intent['select'] ?? [];
        if (
            !is_array($select)
            || !array_is_list($select)
            || count($select) > 30
        ) {
            throw new InvalidArgumentException(
                'Intent select must be a list of at most 30 field IDs.'
            );
        }

        $selectedFields = [];
        foreach ($select as $fieldId) {
            $selectedFields[] = $this->resolveField($fieldId, $candidates);
        }

        $aggregations = $intent['aggregations'] ?? [];
        if (
            !is_array($aggregations)
            || !array_is_list($aggregations)
            || count($aggregations) > 30
        ) {
            throw new InvalidArgumentException(
                'Intent aggregations must be a list of at most 30 entries.'
            );
        }

        $resolvedAggregations = [];
        foreach ($aggregations as $aggregation) {
            if (!is_array($aggregation)) {
                throw new InvalidArgumentException('Each aggregation must be an object.');
            }

            $this->assertKeys($aggregation, ['function', 'field', 'alias']);
            $function = $aggregation['function'] ?? null;
            if (!in_array($function, ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'], true)) {
                throw new InvalidArgumentException('The aggregation function is unsupported.');
            }

            $resolved = [
                'function' => $function,
                'field' => $this->resolveField($aggregation['field'] ?? null, $candidates),
            ];
            if (array_key_exists('alias', $aggregation)) {
                if (
                    !is_string($aggregation['alias'])
                    || preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/D', $aggregation['alias']) !== 1
                ) {
                    throw new InvalidArgumentException('The aggregation alias is invalid.');
                }
                $resolved['alias'] = $aggregation['alias'];
            }
            $resolvedAggregations[] = $resolved;
        }

        if ($selectedFields === [] && $resolvedAggregations === []) {
            throw new InvalidArgumentException(
                'Intent must select at least one field or aggregation.'
            );
        }

        $filters = $intent['filters'] ?? [];
        if (!is_array($filters) || !array_is_list($filters) || count($filters) > 30) {
            throw new InvalidArgumentException('Intent filters must be a list of at most 30 entries.');
        }

        $resolvedFilters = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                throw new InvalidArgumentException('Each intent filter must be an object.');
            }

            $this->assertKeys($filter, ['field', 'operator', 'value']);
            $field = $this->resolveField($filter['field'] ?? null, $candidates);
            $operator = $filter['operator'] ?? null;
            if (!in_array(
                $operator,
                ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'IS NULL', 'IS NOT NULL'],
                true,
            )) {
                throw new InvalidArgumentException('The intent filter operator is unsupported.');
            }

            if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                if (array_key_exists('value', $filter)) {
                    throw new InvalidArgumentException(
                        'Null-check intent filters must omit value.'
                    );
                }
            } elseif (
                !array_key_exists('value', $filter)
                || (!is_scalar($filter['value']) && $filter['value'] !== null)
            ) {
                throw new InvalidArgumentException(
                    'Comparison intent filters require a scalar value.'
                );
            }

            $resolvedFilters[] = [
                'field' => $field,
                'operator' => $operator,
                'has_value' => array_key_exists('value', $filter),
                'value' => $filter['value'] ?? null,
            ];
        }

        $orderBy = $intent['order_by'] ?? [];
        if (!is_array($orderBy) || !array_is_list($orderBy) || count($orderBy) > 10) {
            throw new InvalidArgumentException('Intent order_by must be a list of at most 10 entries.');
        }

        $resolvedOrder = [];
        foreach ($orderBy as $order) {
            if (!is_array($order)) {
                throw new InvalidArgumentException('Each intent order_by entry must be an object.');
            }

            $this->assertKeys($order, ['field', 'direction']);
            $direction = $order['direction'] ?? null;
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException('Intent sort direction must be ASC or DESC.');
            }

            $resolvedOrder[] = [
                'field' => $this->resolveField($order['field'] ?? null, $candidates),
                'direction' => $direction,
            ];
        }

        $groupBy = $intent['group_by'] ?? [];
        if (!is_array($groupBy) || !array_is_list($groupBy) || count($groupBy) > 30) {
            throw new InvalidArgumentException('Intent group_by must be a list of at most 30 fields.');
        }
        $resolvedGroups = [];
        foreach ($groupBy as $fieldId) {
            $resolvedGroups[] = $this->resolveField($fieldId, $candidates);
        }

        $limit = $intent['limit'] ?? self::DEFAULT_LIMIT;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(
                'The query limit must be an integer between 1 and '
                . self::MAX_LIMIT . '.'
            );
        }

        $requiredFields = [
            ...$selectedFields,
            ...array_column($resolvedAggregations, 'field'),
            ...array_column($resolvedFilters, 'field'),
            ...array_column($resolvedOrder, 'field'),
            ...$resolvedGroups,
        ];
        $baseTable = $requiredFields[0]['table'];
        $requiredTables = array_values(array_unique(array_column($requiredFields, 'table')));
        $requiredFieldIds = [];
        $fieldConcepts = $this->indexFieldConcepts($schemaContext);
        $requiredConcepts = [];
        foreach ($requiredFields as $field) {
            $fieldId = $field['table'] . '.' . $field['column'];
            $requiredFieldIds[$fieldId] = true;
            foreach ($fieldConcepts[$fieldId] ?? [] as $concept) {
                $requiredConcepts[$concept] = true;
            }
        }
        $aliases = [$baseTable => 't0'];
        $joins = [];

        foreach ($requiredTables as $table) {
            if (isset($aliases[$table])) {
                continue;
            }

            $path = $this->shortestDeclaredPath(
                array_keys($aliases),
                $table,
                $schema['foreign_keys'] ?? [],
                $requiredFieldIds,
                $requiredConcepts,
                $fieldConcepts,
            );

            foreach ($path as $edge) {
                $newTable = $edge['source_table'] === $edge['current_table']
                    ? $edge['target_table']
                    : $edge['source_table'];

                if (isset($aliases[$newTable])) {
                    continue;
                }

                $newAlias = 't' . count($aliases);
                $existingTable = $edge['current_table'];
                $conditions = [];
                foreach ($edge['columns'] as $pair) {
                    $existingColumn = $edge['source_table'] === $existingTable
                        ? $pair['source_column']
                        : $pair['target_column'];
                    $newColumn = $edge['source_table'] === $newTable
                        ? $pair['source_column']
                        : $pair['target_column'];
                    $conditions[] = [
                        'left' => [
                            'table' => $aliases[$existingTable],
                            'column' => $existingColumn,
                        ],
                        'operator' => '=',
                        'right' => [
                            'table' => $newAlias,
                            'column' => $newColumn,
                        ],
                    ];
                }

                $joins[] = [
                    'type' => 'INNER',
                    'table' => $newTable,
                    'alias' => $newAlias,
                    'on' => $conditions,
                ];
                $aliases[$newTable] = $newAlias;
            }

            if (!isset($aliases[$table])) {
                throw new InvalidArgumentException(
                    "Could not connect table '{$table}' using declared foreign keys; "
                    . 'connected tables: ' . implode(', ', array_keys($aliases)) . '.'
                );
            }
        }

        if (count($joins) > self::MAX_JOINS) {
            throw new InvalidArgumentException('The required join path exceeds the join limit.');
        }

        $compiledSelect = array_map(
            fn (array $field): array => $this->columnReference($field, $aliases),
            $selectedFields,
        );
        foreach ($resolvedAggregations as $aggregation) {
            $aggregate = [
                ...$this->columnReference($aggregation['field'], $aliases),
                'aggregate' => $aggregation['function'],
            ];
            if (isset($aggregation['alias'])) {
                $aggregate['alias'] = $aggregation['alias'];
            }
            $compiledSelect[] = $aggregate;
        }

        $plan = [
            'select' => $compiledSelect,
            'from' => ['table' => $baseTable, 'alias' => 't0'],
            'limit' => $limit,
        ];

        if ($joins !== []) {
            $plan['joins'] = $joins;
        }

        if ($resolvedGroups !== []) {
            $plan['group_by'] = array_map(
                fn (array $field): array => $this->columnReference($field, $aliases),
                $resolvedGroups,
            );
        }

        if ($resolvedFilters !== []) {
            $plan['where'] = array_map(
                function (array $filter) use ($aliases): array {
                    $entry = [
                        'column' => $this->columnReference($filter['field'], $aliases),
                        'operator' => $filter['operator'],
                    ];
                    if ($filter['has_value']) {
                        $entry['value'] = $filter['value'];
                    }
                    return $entry;
                },
                $resolvedFilters,
            );
        }

        if ($resolvedOrder !== []) {
            $plan['order_by'] = array_map(
                fn (array $order): array => [
                    ...$this->columnReference($order['field'], $aliases),
                    'direction' => $order['direction'],
                ],
                $resolvedOrder,
            );
        }

        $this->validator->validate($plan, $schema);
        $this->compiler->compile($plan, $schema);

        return $plan;
    }

    /**
     * @param array<string, mixed> $schemaContext
     * @return array<string, array{table: string, column: string}>
     */
    private function indexCandidates(array $schemaContext): array
    {
        $indexed = [];
        foreach ($schemaContext['field_candidates'] ?? [] as $candidate) {
            if (
                is_array($candidate)
                && is_string($candidate['id'] ?? null)
                && is_string($candidate['table'] ?? null)
                && is_string($candidate['column'] ?? null)
            ) {
                $indexed[$candidate['id']] = [
                    'table' => $candidate['table'],
                    'column' => $candidate['column'],
                ];
            }
        }

        if ($indexed === []) {
            foreach ($schemaContext['tables'] ?? [] as $table) {
                if (
                    !is_array($table)
                    || !is_string($table['name'] ?? null)
                    || !is_array($table['columns'] ?? null)
                ) {
                    continue;
                }

                foreach ($table['columns'] as $column) {
                    if (is_array($column) && is_string($column['name'] ?? null)) {
                        $id = $table['name'] . '.' . $column['name'];
                        $indexed[$id] = [
                            'table' => $table['name'],
                            'column' => $column['name'],
                        ];
                    }
                }
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $schemaContext
     * @return array<string, list<string>>
     */
    private function indexFieldConcepts(array $schemaContext): array
    {
        $indexed = [];
        foreach ($schemaContext['field_candidates'] ?? [] as $candidate) {
            if (
                !is_array($candidate)
                || !is_string($candidate['id'] ?? null)
                || !is_array($candidate['concepts'] ?? null)
            ) {
                continue;
            }

            $indexed[$candidate['id']] = array_values(array_filter(
                $candidate['concepts'],
                'is_string',
            ));
        }

        return $indexed;
    }

    /**
     * @param array<string, array{table: string, column: string}> $candidates
     * @return array{table: string, column: string}
     */
    private function resolveField(mixed $fieldId, array $candidates): array
    {
        if (!is_string($fieldId) || !isset($candidates[$fieldId])) {
            throw new InvalidArgumentException(
                'Intent references a field that is not in the retrieved schema catalog.'
            );
        }

        return $candidates[$fieldId];
    }

    /**
     * @param list<string> $connectedTables
     * @param mixed $foreignKeys
     * @param array<string, true> $requiredFieldIds
     * @param array<string, true> $requiredConcepts
     * @param array<string, list<string>> $fieldConcepts
     * @return list<array{
     *     current_table: string,
     *     source_table: string,
     *     target_table: string,
     *     columns: list<array{source_column: string, target_column: string}>
     * }>
     */
    private function shortestDeclaredPath(
        array $connectedTables,
        string $targetTable,
        mixed $foreignKeys,
        array $requiredFieldIds,
        array $requiredConcepts,
        array $fieldConcepts,
    ): array {
        if (!is_array($foreignKeys)) {
            throw new InvalidArgumentException('Declared foreign-key metadata is invalid.');
        }

        $adjacency = [];
        foreach ($this->groupForeignKeys($foreignKeys) as $foreignKey) {
            $sourceTable = $foreignKey['source_table'];
            $edgeTargetTable = $foreignKey['target_table'];
            $sourceConceptMatches = 0;
            $targetConceptMatches = 0;
            $requiredSourceColumnCount = 0;
            $requiredTargetColumnCount = 0;
            foreach ($foreignKey['columns'] as $pair) {
                $sourceFieldId = $sourceTable . '.' . $pair['source_column'];
                $targetFieldId = $edgeTargetTable . '.' . $pair['target_column'];
                $requiredSourceColumnCount += isset($requiredFieldIds[$sourceFieldId]) ? 1 : 0;
                $requiredTargetColumnCount += isset($requiredFieldIds[$targetFieldId]) ? 1 : 0;
                $sourceConceptMatches += count(array_intersect(
                    $fieldConcepts[$sourceFieldId] ?? [],
                    array_keys($requiredConcepts),
                ));
                $targetConceptMatches += count(array_intersect(
                    $fieldConcepts[$targetFieldId] ?? [],
                    array_keys($requiredConcepts),
                ));
            }

            if (
                $foreignKey['columns'] === []
            ) {
                continue;
            }

            $foreignKey['preference'] = [
                $requiredSourceColumnCount,
                $requiredTargetColumnCount,
                $sourceConceptMatches,
                $targetConceptMatches,
            ];
            $adjacency[$sourceTable][] = [
                ...$foreignKey,
                'source_table' => $sourceTable,
                'target_table' => $edgeTargetTable,
            ];
            $adjacency[$edgeTargetTable][] = [
                ...$foreignKey,
                'source_table' => $sourceTable,
                'target_table' => $edgeTargetTable,
            ];
        }

        $queue = [];
        $distance = [];
        $preferenceScores = [];
        $paths = [];
        foreach ($connectedTables as $table) {
            $distance[$table] = 0;
            $preferenceScores[$table] = [0, 0, 0, 0];
            $paths[$table] = [[]];
            $queue[] = $table;
        }

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $relationship) {
                $neighbor = $relationship['source_table'] === $current
                    ? $relationship['target_table']
                    : $relationship['source_table'];
                $nextDistance = $distance[$current] + 1;
                $nextPreferenceScore = array_map(
                    static fn (int $currentScore, int $edgeScore): int =>
                        $currentScore + $edgeScore,
                    $preferenceScores[$current],
                    $relationship['preference'],
                );
                $relationship['current_table'] = $current;

                if (!isset($distance[$neighbor])) {
                    $distance[$neighbor] = $nextDistance;
                    $preferenceScores[$neighbor] = $nextPreferenceScore;
                    $paths[$neighbor] = [];
                    $queue[] = $neighbor;
                }

                if ($distance[$neighbor] !== $nextDistance) {
                    continue;
                }

                $preferenceComparison = $this->comparePathPreferences(
                    $nextPreferenceScore,
                    $preferenceScores[$neighbor],
                );
                if ($preferenceComparison < 0) {
                    continue;
                }

                if ($preferenceComparison > 0) {
                    $preferenceScores[$neighbor] = $nextPreferenceScore;
                    $paths[$neighbor] = [];
                    $queue[] = $neighbor;
                }

                foreach ($paths[$current] as $path) {
                    $candidate = [...$path, $relationship];
                    $signature = json_encode($candidate, JSON_THROW_ON_ERROR);
                    $known = array_map(
                        static fn (array $knownPath): string =>
                            json_encode($knownPath, JSON_THROW_ON_ERROR),
                        $paths[$neighbor],
                    );
                    if (!in_array($signature, $known, true) && count($paths[$neighbor]) < 2) {
                        $paths[$neighbor][] = $candidate;
                    }
                }
            }
        }

        if (!isset($paths[$targetTable]) || $paths[$targetTable] === []) {
            throw new InvalidArgumentException(
                "No declared relationship path connects to '{$targetTable}'."
            );
        }

        if (count($paths[$targetTable]) > 1) {
            throw new InvalidArgumentException(
                "Multiple equally short declared relationship paths connect to '{$targetTable}'."
            );
        }

        return $paths[$targetTable][0];
    }

    /**
     * @param array<mixed> $foreignKeys
     * @return list<array{
     *     source_table: string,
     *     target_table: string,
     *     columns: list<array{source_column: string, target_column: string}>
     * }>
     */
    private function groupForeignKeys(array $foreignKeys): array
    {
        $groups = [];
        foreach ($foreignKeys as $index => $foreignKey) {
            if (
                !is_array($foreignKey)
                || !is_string($foreignKey['source_table'] ?? null)
                || !is_string($foreignKey['source_column'] ?? null)
                || !is_string($foreignKey['target_table'] ?? null)
                || !is_string($foreignKey['target_column'] ?? null)
            ) {
                continue;
            }

            $constraintName = $foreignKey['name'] ?? null;
            $groupKey = is_string($constraintName)
                ? json_encode([
                    $foreignKey['source_table'],
                    $constraintName,
                    $foreignKey['target_schema'] ?? null,
                    $foreignKey['target_table'],
                ], JSON_THROW_ON_ERROR)
                : 'unnamed:' . $index;

            $groups[$groupKey] ??= [
                'source_table' => $foreignKey['source_table'],
                'target_table' => $foreignKey['target_table'],
                'columns' => [],
            ];
            $groups[$groupKey]['columns'][] = [
                'source_column' => $foreignKey['source_column'],
                'target_column' => $foreignKey['target_column'],
                'ordinal_position' => $foreignKey['ordinal_position'] ?? $index,
            ];
        }

        foreach ($groups as &$group) {
            usort(
                $group['columns'],
                static fn (array $left, array $right): int =>
                    $left['ordinal_position'] <=> $right['ordinal_position'],
            );
            foreach ($group['columns'] as &$column) {
                unset($column['ordinal_position']);
            }
            unset($column);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * Preference tuple order: explicitly required FK column, explicitly
     * required referenced column, matching FK semantic, matching target
     * semantic. Equal tuples remain ambiguous and are rejected.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    private function comparePathPreferences(array $left, array $right): int
    {
        foreach ($left as $index => $score) {
            $comparison = $score <=> ($right[$index] ?? 0);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * @param array{table: string, column: string} $field
     * @param array<string, string> $aliases
     * @return array{table: string, column: string}
     */
    private function columnReference(array $field, array $aliases): array
    {
        return [
            'table' => $aliases[$field['table']],
            'column' => $field['column'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private function assertKeys(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('The query intent contains an unsupported field.');
            }
        }
    }
}
