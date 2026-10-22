<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Query;

use InvalidArgumentException;

final class QueryPlanValidator
{
    /**
     * Validate that every join follows a declared foreign-key relationship
     * and connects its new table to the existing query.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $schema
     */
    public function validate(array $plan, array $schema): void
    {
        $from = $plan['from'] ?? null;

        if (
            !is_array($from)
            || !is_string($from['table'] ?? null)
            || !is_string($from['alias'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'The query plan requires a valid base table and alias.'
            );
        }

        $tables = [];
        foreach ($schema['tables'] ?? [] as $table) {
            if (is_array($table) && is_string($table['name'] ?? null)) {
                $tables[$table['name']] = true;
            }
        }

        if (!isset($tables[$from['table']])) {
            throw new InvalidArgumentException(
                'The query plan uses an unknown base table.'
            );
        }

        $aliases = [
            $from['alias'] => $from['table'],
        ];

        $joins = $plan['joins'] ?? [];

        if (!is_array($joins) || !array_is_list($joins)) {
            throw new InvalidArgumentException(
                'The query plan joins must be a list.'
            );
        }

        $foreignKeys = $schema['foreign_keys'] ?? [];

        if (!is_array($foreignKeys)) {
            throw new InvalidArgumentException(
                'The schema foreign-key metadata is invalid.'
            );
        }
        $groupedForeignKeys = $this->groupForeignKeys($foreignKeys);

        foreach ($joins as $join) {
            if (
                !is_array($join)
                || !is_string($join['table'] ?? null)
                || !is_string($join['alias'] ?? null)
                || !is_array($join['on'] ?? null)
                || !array_is_list($join['on'])
                || $join['on'] === []
            ) {
                throw new InvalidArgumentException(
                    'Each join requires a table, alias, and non-empty condition list.'
                );
            }

            $table = $join['table'];
            $alias = $join['alias'];

            if (!isset($tables[$table])) {
                throw new InvalidArgumentException(
                    'The query plan joins an unknown table.'
                );
            }

            if (isset($aliases[$alias])) {
                throw new InvalidArgumentException(
                    'Table aliases must be unique.'
                );
            }

            $matchedConstraintPairs = [];

            foreach ($join['on'] as $condition) {
                if (
                    !is_array($condition)
                    || ($condition['operator'] ?? null) !== '='
                    || !is_array($condition['left'] ?? null)
                    || !is_array($condition['right'] ?? null)
                ) {
                    throw new InvalidArgumentException(
                        'Join conditions must use equality between column references.'
                    );
                }

                $left = $condition['left'];
                $right = $condition['right'];

                if (
                    !is_string($left['table'] ?? null)
                    || !is_string($left['column'] ?? null)
                    || !is_string($right['table'] ?? null)
                    || !is_string($right['column'] ?? null)
                ) {
                    throw new InvalidArgumentException(
                        'Join conditions require valid alias and column references.'
                    );
                }

                $leftAlias = $left['table'];
                $rightAlias = $right['table'];

                // Each condition must connect the new table to an already
                // registered alias. This rejects disconnected and self-only joins.
                $leftIsNew = $leftAlias === $alias;
                $rightIsNew = $rightAlias === $alias;

                if ($leftIsNew === $rightIsNew) {
                    throw new InvalidArgumentException(
                        'Each join condition must connect the joined table '
                        . 'to an existing table alias.'
                    );
                }

                $existingAlias = $leftIsNew ? $rightAlias : $leftAlias;

                if (!isset($aliases[$existingAlias])) {
                    throw new InvalidArgumentException(
                        'A join references an alias that is not yet connected.'
                    );
                }

                $newColumn = $leftIsNew ? $left['column'] : $right['column'];
                $existingColumn = $leftIsNew ? $right['column'] : $left['column'];
                $existingTable = $aliases[$existingAlias];
                $conditionMatched = false;
                foreach ($groupedForeignKeys as $key => $foreignKey) {
                    $newIsSource = $foreignKey['source_table'] === $table
                        && $foreignKey['target_table'] === $existingTable;
                    $newIsTarget = $foreignKey['target_table'] === $table
                        && $foreignKey['source_table'] === $existingTable;
                    if (!$newIsSource && !$newIsTarget) {
                        continue;
                    }

                    foreach ($foreignKey['columns'] as $pairIndex => $pair) {
                        $expectedNewColumn = $newIsSource
                            ? $pair['source_column']
                            : $pair['target_column'];
                        $expectedExistingColumn = $newIsSource
                            ? $pair['target_column']
                            : $pair['source_column'];
                        if (
                            $newColumn === $expectedNewColumn
                            && $existingColumn === $expectedExistingColumn
                        ) {
                            $matchedConstraintPairs[$key][$pairIndex] = true;
                            $conditionMatched = true;
                        }
                    }
                }

                if (!$conditionMatched) {
                    throw new InvalidArgumentException(
                        'A join condition does not match a declared foreign-key relationship.'
                    );
                }
            }

            if ($matchedConstraintPairs === []) {
                throw new InvalidArgumentException(
                    'The join does not match a declared foreign-key relationship.'
                );
            }

            foreach ($matchedConstraintPairs as $key => $matchedPairs) {
                $expectedPairCount = count($groupedForeignKeys[$key]['columns'] ?? []);
                if ($expectedPairCount === 0 || count($matchedPairs) !== $expectedPairCount) {
                    throw new InvalidArgumentException(
                        'A join must include every column pair from its declared foreign-key constraint.'
                    );
                }
            }

            // Register only after validating the join, so a join cannot
            // reference itself or a later table as if already connected.
            $aliases[$alias] = $table;
        }
    }

    /**
     * @param array<mixed> $foreignKeys
     * @return array<string, array{
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

            $name = $foreignKey['name'] ?? null;
            $key = is_string($name)
                ? json_encode([
                    $foreignKey['source_table'],
                    $name,
                    $foreignKey['target_schema'] ?? null,
                    $foreignKey['target_table'],
                ], JSON_THROW_ON_ERROR)
                : 'unnamed:' . $index;
            $groups[$key] ??= [
                'source_table' => $foreignKey['source_table'],
                'target_table' => $foreignKey['target_table'],
                'columns' => [],
            ];
            $groups[$key]['columns'][] = [
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

        return $groups;
    }
}
