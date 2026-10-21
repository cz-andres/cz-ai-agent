<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

use InvalidArgumentException;

final class ReadOnlyQueryPlanCompiler
{
    private const MAX_LIMIT = 100;
    private const MAX_SELECT_COLUMNS = 30;
    private const MAX_JOINS = 8;
    private const MAX_FILTERS = 30;
    private const MAX_ORDER_COLUMNS = 10;

    /**
     * Validate a structured plan against the discovered schema and compile it.
     *
     * Column references use table aliases, not physical table names.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $schema
     * @return array{sql: string, parameters: list<mixed>}
     */
    public function compile(array $plan, array $schema): array
    {
        $this->assertKeys($plan, [
            'select', 'from', 'joins', 'where', 'order_by', 'limit',
        ]);

        $tables = $this->indexTables($schema);
        $columns = $this->indexColumns($schema);

        $from = $plan['from'] ?? null;

        if (!is_array($from)) {
            throw new InvalidArgumentException('The query plan requires a from object.');
        }

        $this->assertKeys($from, ['table', 'alias']);
        $baseTable = $this->requiredIdentifier($from, 'table');
        $baseAlias = $this->requiredIdentifier($from, 'alias');

        if (!isset($tables[$baseTable])) {
            throw new InvalidArgumentException('The query references an unknown base table.');
        }

        $aliases = [$baseAlias => $baseTable];

        $joins = $plan['joins'] ?? [];
        if (!is_array($joins) || !array_is_list($joins) || count($joins) > self::MAX_JOINS) {
            throw new InvalidArgumentException('The joins list is invalid or exceeds the limit.');
        }

        foreach ($joins as $join) {
            if (!is_array($join)) {
                throw new InvalidArgumentException('Each join must be an object.');
            }

            $this->assertKeys($join, ['type', 'table', 'alias', 'on']);

            $table = $this->requiredIdentifier($join, 'table');
            $alias = $this->requiredIdentifier($join, 'alias');

            if (!isset($tables[$table])) {
                throw new InvalidArgumentException('The query references an unknown joined table.');
            }

            if (isset($aliases[$alias])) {
                throw new InvalidArgumentException('Table aliases must be unique.');
            }

            if (!in_array($join['type'] ?? null, ['INNER', 'LEFT'], true)) {
                throw new InvalidArgumentException('Only INNER and LEFT joins are supported.');
            }

            $aliases[$alias] = $table;
        }

        $parameters = [];
        $select = $plan['select'] ?? null;

        if (
            !is_array($select)
            || !array_is_list($select)
            || $select === []
            || count($select) > self::MAX_SELECT_COLUMNS
        ) {
            throw new InvalidArgumentException('The select list is invalid or exceeds the limit.');
        }

        $selectParts = [];

        foreach ($select as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Each selected column must be an object.');
            }

            $this->assertKeys($item, ['table', 'column', 'alias']);

            $reference = $this->validateColumnReference(
                $item,
                $aliases,
                $columns,
                ['alias']
            );

            $part = $this->quoteIdentifier($reference['alias'])
                . '.'
                . $this->quoteIdentifier($reference['column']);

            if (isset($item['alias'])) {
                $outputAlias = $this->identifier($item['alias']);
                $part .= ' AS ' . $this->quoteIdentifier($outputAlias);
            }

            $selectParts[] = $part;
        }

        $joinParts = [];
        $availableAliases = [$baseAlias => $baseTable];

        foreach ($joins as $join) {
            $availableAliases[$join['alias']] = $join['table'];
            $conditions = $join['on'] ?? null;

            if (
                !is_array($conditions)
                || !array_is_list($conditions)
                || $conditions === []
                || count($conditions) > 10
            ) {
                throw new InvalidArgumentException('Each join requires 1 to 10 conditions.');
            }

            $conditionParts = [];

            foreach ($conditions as $condition) {
                if (!is_array($condition)) {
                    throw new InvalidArgumentException('Each join condition must be an object.');
                }

                $this->assertKeys($condition, ['left', 'operator', 'right']);

                if (($condition['operator'] ?? null) !== '=') {
                    throw new InvalidArgumentException('Join conditions only support equality.');
                }

                $left = $this->validateColumnReference(
                    $condition['left'] ?? [],
                    $availableAliases,
                    $columns
                );
                $right = $this->validateColumnReference(
                    $condition['right'] ?? [],
                    $availableAliases,
                    $columns
                );

                $conditionParts[] = $this->quoteIdentifier($left['alias'])
                    . '.' . $this->quoteIdentifier($left['column'])
                    . ' = '
                    . $this->quoteIdentifier($right['alias'])
                    . '.' . $this->quoteIdentifier($right['column']);
            }

            $joinParts[] = $join['type']
                . ' JOIN ' . $this->quoteIdentifier($join['table'])
                . ' AS ' . $this->quoteIdentifier($join['alias'])
                . ' ON (' . implode(' AND ', $conditionParts) . ')';
        }

        $where = $plan['where'] ?? [];
        if (
            !is_array($where)
            || !array_is_list($where)
            || count($where) > self::MAX_FILTERS
        ) {
            throw new InvalidArgumentException('The where list is invalid or exceeds the limit.');
        }

        $whereParts = [];

        foreach ($where as $filter) {
            if (!is_array($filter)) {
                throw new InvalidArgumentException('Each filter must be an object.');
            }

            $this->assertKeys($filter, ['column', 'operator', 'value']);

            $reference = $this->validateColumnReference(
                $filter['column'] ?? [],
                $aliases,
                $columns
            );

            $columnSql = $this->quoteIdentifier($reference['alias'])
                . '.' . $this->quoteIdentifier($reference['column']);
            $operator = $filter['operator'] ?? null;

            if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                if (array_key_exists('value', $filter)) {
                    throw new InvalidArgumentException(
                        'IS NULL filters must not include a value.'
                    );
                }

                $whereParts[] = $columnSql . ' ' . $operator;
                continue;
            }

            if (!in_array($operator, ['=', '!=', '>', '>=', '<', '<=', 'LIKE'], true)) {
                throw new InvalidArgumentException('The filter operator is not supported.');
            }

            if (!array_key_exists('value', $filter)) {
                throw new InvalidArgumentException('Each comparison filter requires a value.');
            }

            $value = $filter['value'];

            if (
                !is_scalar($value)
                && $value !== null
            ) {
                throw new InvalidArgumentException('Filter values must be scalar or null.');
            }

            if ($value === null && in_array($operator, ['=', '!='], true)) {
                $whereParts[] = $columnSql
                    . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL');
                continue;
            }

            if ($value === null) {
                throw new InvalidArgumentException('This operator cannot compare a null value.');
            }

            $whereParts[] = $columnSql . ' ' . $operator . ' ?';
            $parameters[] = $value;
        }

        $orderBy = $plan['order_by'] ?? [];
        if (
            !is_array($orderBy)
            || !array_is_list($orderBy)
            || count($orderBy) > self::MAX_ORDER_COLUMNS
        ) {
            throw new InvalidArgumentException('The order_by list is invalid or exceeds the limit.');
        }

        $orderParts = [];

        foreach ($orderBy as $order) {
            if (!is_array($order)) {
                throw new InvalidArgumentException('Each order_by entry must be an object.');
            }

            $this->assertKeys($order, ['table', 'column', 'direction']);

            $reference = $this->validateColumnReference(
                $order,
                $aliases,
                $columns,
                ['direction']
            );

            $direction = $order['direction'] ?? null;

            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException('Sort direction must be ASC or DESC.');
            }

            $orderParts[] = $this->quoteIdentifier($reference['alias'])
                . '.' . $this->quoteIdentifier($reference['column'])
                . ' ' . $direction;
        }

        $limit = $plan['limit'] ?? null;

        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(
                'The query limit must be an integer between 1 and '
                . self::MAX_LIMIT . '.'
            );
        }

        $sql = 'SELECT ' . implode(', ', $selectParts)
            . ' FROM ' . $this->quoteIdentifier($baseTable)
            . ' AS ' . $this->quoteIdentifier($baseAlias);

        if ($joinParts !== []) {
            $sql .= ' ' . implode(' ', $joinParts);
        }

        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        if ($orderParts !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        $sql .= ' LIMIT ' . $limit;

        return [
            'sql' => $sql,
            'parameters' => $parameters,
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, true>
     */
    private function indexTables(array $schema): array
    {
        $indexed = [];

        foreach ($schema['tables'] ?? [] as $table) {
            if (is_array($table) && is_string($table['name'] ?? null)) {
                $indexed[$table['name']] = true;
            }
        }

        if ($indexed === []) {
            throw new InvalidArgumentException('The schema contains no valid tables.');
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, array<string, true>>
     */
    private function indexColumns(array $schema): array
    {
        $indexed = [];

        foreach ($schema['columns'] ?? [] as $column) {
            if (
                is_array($column)
                && is_string($column['table_name'] ?? null)
                && is_string($column['name'] ?? null)
            ) {
                $indexed[$column['table_name']][$column['name']] = true;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $reference
     * @param array<string, string> $aliases
     * @param array<string, array<string, true>> $columns
     * @param list<string> $additionalAllowedKeys
     * @return array{alias: string, column: string}
     */
    private function validateColumnReference(
        mixed $reference,
        array $aliases,
        array $columns,
        array $additionalAllowedKeys = [],
    ): array {
        if (!is_array($reference)) {
            throw new InvalidArgumentException('A column reference must be an object.');
        }

        $this->assertKeys($reference, ['table', 'column', ...$additionalAllowedKeys]);

        $alias = $this->requiredIdentifier($reference, 'table');
        $column = $this->requiredIdentifier($reference, 'column');

        if (!isset($aliases[$alias])) {
            throw new InvalidArgumentException('A column reference uses an unknown table alias.');
        }

        $physicalTable = $aliases[$alias];

        if (!isset($columns[$physicalTable][$column])) {
            throw new InvalidArgumentException('The query references an unknown column.');
        }

        return ['alias' => $alias, 'column' => $column];
    }

    /**
     * Reject unexpected keys so the plan has a small, explicit grammar.
     *
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    private function assertKeys(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('The query plan contains an unsupported field.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredIdentifier(array $data, string $key): string
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException("The query plan is missing {$key}.");
        }

        return $this->identifier($data[$key]);
    }

    private function identifier(mixed $value): string
    {
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > 128
            || preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('A query identifier is invalid.');
        }

        return $value;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
