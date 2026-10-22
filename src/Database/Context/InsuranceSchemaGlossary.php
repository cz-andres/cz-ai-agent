<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Context;

final class InsuranceSchemaGlossary
{
    /**
     * @return list<string>
     */
    public function matchedConcepts(string $question): array
    {
        $concepts = [];
        $patterns = [
            'policy_id' => '/\bpolicy(?:[_\s-]+(?:id|identifier))\b/i',
            'policy_number' => '/\bpolicy(?:[_\s-]+(?:number|no\.?))\b/i',
            'period_id' => '/\bperiod(?:[_\s-]+(?:id|identifier))\b/i',
            'current_period_id' => '/\bcurrent\s+period\b/i',
            'claim_id' => '/\bclaim(?:[_\s-]+(?:id|identifier))\b/i',
            'claim_number' => '/\bclaim(?:[_\s-]+(?:number|no\.?))\b/i',
            'customer_id' => '/\b(?:customer|client)(?:[_\s-]+(?:id|identifier))\b/i',
        ];

        foreach ($patterns as $concept => $pattern) {
            if (preg_match($pattern, $question) === 1) {
                $concepts[] = $concept;
            }
        }

        if (
            preg_match('/\bpolicy\s+[A-Za-z][A-Za-z0-9-]*\d[A-Za-z0-9-]*\b/i', $question) === 1
        ) {
            $concepts[] = 'policy_number';
        }

        if (
            preg_match('/\bclaim\s+[A-Za-z][A-Za-z0-9-]*\d[A-Za-z0-9-]*\b/i', $question) === 1
        ) {
            $concepts[] = 'claim_number';
        }

        return $concepts;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{concepts: list<string>, canonical_concepts: list<string>}
     */
    public function fieldSemantics(
        string $tableName,
        string $columnName,
        array $schema,
    ): array {
        $concepts = [];
        $canonicalConcepts = [];

        $canonicalFields = [
            'n_policies.id' => 'policy_id',
            'n_policies.policy_number' => 'policy_number',
            'n_policies.current_period_id' => 'current_period_id',
            'n_policy_periods.id' => 'period_id',
            'n_policy_claims.id' => 'claim_id',
            'n_policy_claims.claim_number' => 'claim_number',
        ];

        $qualifiedName = $tableName . '.' . $columnName;
        if (isset($canonicalFields[$qualifiedName])) {
            $concept = $canonicalFields[$qualifiedName];
            $concepts[] = $concept;
            $canonicalConcepts[] = $concept;
        }

        if ($this->isCustomerEntityKey($tableName, $columnName, $schema)) {
            $concepts[] = 'customer_id';
            $canonicalConcepts[] = 'customer_id';
        }

        foreach ($schema['foreign_keys'] ?? [] as $foreignKey) {
            if (!is_array($foreignKey)) {
                continue;
            }

            if (
                ($foreignKey['source_table'] ?? null) !== $tableName
                || ($foreignKey['source_column'] ?? null) !== $columnName
            ) {
                continue;
            }

            $targetTable = $foreignKey['target_table'] ?? null;
            $targetColumn = $foreignKey['target_column'] ?? null;
            $concept = is_string($targetTable) && is_string($targetColumn)
                ? ($canonicalFields[$targetTable . '.' . $targetColumn]
                    ?? ($this->isCustomerEntityKey($targetTable, $targetColumn, $schema)
                        ? 'customer_id'
                        : null))
                : null;

            if ($concept !== null) {
                $concepts[] = $concept;
            }
        }

        return [
            'concepts' => array_values(array_unique($concepts)),
            'canonical_concepts' => array_values(array_unique($canonicalConcepts)),
        ];
    }

    /**
     * Only treat an ID as a customer identifier when a declared relationship
     * or primary-key metadata ties it to a customer/client entity table.
     *
     * @param array<string, mixed> $schema
     */
    private function isCustomerEntityKey(
        string $tableName,
        string $columnName,
        array $schema,
    ): bool {
        $tableWords = preg_replace('/[_$]+/', ' ', $tableName) ?? $tableName;
        if (
            preg_match('/\b(customers?|clients?)\b/i', $tableWords) !== 1
        ) {
            return false;
        }

        foreach ($schema['columns'] ?? [] as $column) {
            if (
                is_array($column)
                && ($column['table_name'] ?? null) === $tableName
                && ($column['name'] ?? null) === $columnName
                && ($column['is_primary_key'] ?? false) === true
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function matchedColumnNames(string $question): array
    {
        $terms = [
            'policy identifier' => ['policy_id'],
            'policy id' => ['policy_id'],
            'policy number' => ['policy_number'],
            'period identifier' => ['period_id', 'policy_period_id'],
            'policy period' => ['period_id', 'policy_period_id'],
            'period id' => ['period_id', 'policy_period_id'],
            'current period' => ['current_period_id'],
            'base premium' => ['base_premium'],
            'premium amount' => [
                'base_premium',
                'premium_amount',
                'gross_premium',
                'net_premium',
            ],
            'premium' => [
                'base_premium',
                'premium_amount',
                'gross_premium',
                'net_premium',
            ],
            'claim id' => ['claim_id'],
            'claim identifier' => ['claim_id'],
            'claim number' => ['claim_number'],
            'customer id' => ['customer_id'],
            'customer identifier' => ['customer_id'],
            'client id' => ['customer_id'],
            'client identifier' => ['customer_id'],
        ];

        $matched = [];
        foreach ($terms as $phrase => $columns) {
            if (preg_match(
                '/(?<![A-Za-z0-9_$])' . preg_quote($phrase, '/') . '(?![A-Za-z0-9_$])/i',
                $question,
            ) === 1) {
                foreach ($columns as $column) {
                    $matched[$column][] = $phrase;
                }
            }
        }

        return $matched;
    }
}
