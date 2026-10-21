<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

final class SchemaRelationshipAnalyzer
{
    /**
     * Analyse declared foreign keys and infer possible relationships.
     *
     * Inferred relationships are candidates only, never confirmed facts.
     *
     * @param array{
     *     database: string,
     *     tables: list<array<string, mixed>>,
     *     columns: list<array<string, mixed>>,
     *     foreign_keys: list<array<string, mixed>>
     * } $schema
     * @return array{
     *     declared_relationships: list<array<string, mixed>>,
     *     inferred_candidates: list<array<string, mixed>>
     * }
     */
    public function analyze(array $schema): array
    {
        $columnsByTable = [];

        foreach ($schema['columns'] as $column) {
            $columnsByTable[(string) $column['table_name']][] = $column;
        }

        $declared = [];
        $declaredKeys = [];

        foreach ($schema['foreign_keys'] as $foreignKey) {
            $sourceTable = (string) $foreignKey['source_table'];
            $sourceColumn = (string) $foreignKey['source_column'];
            $targetTable = (string) $foreignKey['target_table'];
            $targetColumn = (string) $foreignKey['target_column'];

            $declared[] = [
                'constraint_name' => $foreignKey['name'],
                'source_table' => $sourceTable,
                'source_column' => $sourceColumn,
                'target_schema' => $foreignKey['target_schema'],
                'target_table' => $targetTable,
                'target_column' => $targetColumn,
                'status' => 'declared_foreign_key',
            ];

            $declaredKeys[$this->relationshipKey(
                $sourceTable,
                $sourceColumn,
                $targetTable,
                $targetColumn,
            )] = true;
        }

        $targetKeysByTable = [];

        foreach ($columnsByTable as $tableName => $tableColumns) {
            foreach ($tableColumns as $metadata) {
                if (
                    !($metadata['is_primary_key'] ?? false)
                    && !($metadata['is_unique'] ?? false)
                ) {
                    continue;
                }

                $targetKeysByTable[$tableName][] = $metadata;
            }
        }

        $genericEntityNames = [
            'id',
            'uuid',
            'guid',
            'code',
            'name',
            'type',
            'status',
            'attribute',
            'value',
            'key',
            'description',
        ];

        $candidates = [];
        $candidateKeys = [];

        foreach ($schema['columns'] as $column) {
            $sourceTable = (string) $column['table_name'];
            $sourceColumn = (string) $column['name'];
            $sourceName = $this->normaliseColumnName($sourceColumn);
            $stem = $this->relationshipStem($sourceColumn);

            // Generic names without a recognisable relationship suffix
            // cannot independently establish an entity relationship.
            if (
                $stem === null
                || in_array($stem, $genericEntityNames, true)
            ) {
                continue;
            }

            foreach ($targetKeysByTable as $targetTable => $targetKeys) {
                if ($targetTable === $sourceTable) {
                    continue;
                }


                $containsEntity = $this->tableContainsEntity($targetTable, $stem);

                if (!$containsEntity) {
                    continue;
                }

                $matchingNameKeys = array_values(array_filter(
                    $targetKeys,
                    fn (array $target): bool =>
                        $this->normaliseColumnName((string) $target['name'])
                        === $sourceName,
                ));

                if (
                    !$this->tableEndsWithEntity($targetTable, $stem)
                    && $matchingNameKeys === []
                ) {
                    continue;
                }

                $keysToConsider = $matchingNameKeys !== []
                    ? $matchingNameKeys
                    : $targetKeys;

                foreach ($keysToConsider as $targetMetadata) {
                    $targetColumn = (string) $targetMetadata['name'];

                    $relationshipKey = $this->relationshipKey(
                        $sourceTable,
                        $sourceColumn,
                        $targetTable,
                        $targetColumn,
                    );

                    if (
                        isset($declaredKeys[$relationshipKey])
                        || isset($candidateKeys[$relationshipKey])
                    ) {
                        continue;
                    }

                    $sourceType = $this->typeFamily(
                        (string) $column['data_type'],
                    );

                    $targetType = $this->typeFamily(
                        (string) $targetMetadata['data_type'],
                    );

                    $columnNameMatches = $sourceName ===
                        $this->normaliseColumnName($targetColumn);

                    $candidates[] = [
                        'source_table' => $sourceTable,
                        'source_column' => $sourceColumn,
                        'target_table' => $targetTable,
                        'target_column' => $targetColumn,
                        'source_type_family' => $sourceType,
                        'target_type_family' => $targetType,
                        'type_compatible' => $sourceType === $targetType,
                        'target_column_is_primary_key' =>
                            (bool) ($targetMetadata['is_primary_key'] ?? false),
                        'target_column_is_unique' =>
                            (bool) ($targetMetadata['is_unique'] ?? false),
                        'evidence' => array_values(array_filter([
                            'column_suffix_matches_target_table',
                            $columnNameMatches
                                ? 'column_name_matches_target_key'
                                : null,
                        ])),
                        'status' => 'inferred_unverified',
                    ];

                    $candidateKeys[$relationshipKey] = true;
                }
            }
        }

        return [
            'declared_relationships' => $declared,
            'inferred_candidates' => $candidates,
        ];
    }

    private function relationshipStem(string $columnName): ?string
    {
        if (!preg_match(
            '/^(.+)_(id|ref|uuid|guid)$/i',
            $columnName,
            $matches,
        )) {
            return null;
        }

        $stem = strtolower($matches[1]);

        return $stem !== '' ? $stem : null;
    }

    /**
     * Match the entity name against complete table-name tokens.
     *
     * Supports names such as customers, access_types, and categories,
     * without treating an arbitrary substring as a match.
     */
    private function tableContainsEntity(
        string $tableName,
        string $stem,
    ): bool {
        $tableTokens = array_map(
            fn (string $token): string => $this->singularise($token),
            explode('_', strtolower($tableName)),
        );

        $stemTokens = array_map(
            fn (string $token): string => $this->singularise($token),
            explode('_', strtolower($stem)),
        );

        $stemLength = count($stemTokens);

        if ($stemLength === 0 || $stemLength > count($tableTokens)) {
            return false;
        }

        for (
            $offset = 0;
            $offset <= count($tableTokens) - $stemLength;
            $offset++
        ) {
            if (
                array_slice($tableTokens, $offset, $stemLength)
                === $stemTokens
            ) {
                return true;
            }
        }

        return false;
    }

    private function tableEndsWithEntity(
        string $tableName,
        string $stem,
): bool {
        $tableTokens = array_map(
            fn (string $token): string => $this->singularise($token),
            explode('_', strtolower($tableName)),
    );

        $stemTokens = array_map(
            fn (string $token): string => $this->singularise($token),
            explode('_', strtolower($stem)),
    );

        $stemLength = count($stemTokens);

        if ($stemLength === 0 || $stemLength > count($tableTokens)) {
            return false;
        }

        return array_slice($tableTokens, -$stemLength) === $stemTokens;
    }

    private function singularise(string $word): string
    {
        if (strlen($word) > 3 && str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }

        if (
            strlen($word) > 2
            && str_ends_with($word, 's')
            && !str_ends_with($word, 'ss')
        ) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    private function relationshipKey(
        string $sourceTable,
        string $sourceColumn,
        string $targetTable,
        string $targetColumn,
    ): string {
        return strtolower(implode('.', [
            $sourceTable,
            $sourceColumn,
            $targetTable,
            $targetColumn,
        ]));
    }

    private function normaliseColumnName(string $name): string
    {
        return strtolower(
            preg_replace('/[^a-zA-Z0-9]/', '', $name) ?? $name,
        );
    }

    private function typeFamily(string $dataType): string
    {
        $type = strtolower(trim($dataType));

        if (in_array($type, [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'serial', 'bigserial',
        ], true)) {
            return 'integer';
        }

        if (in_array($type, [
            'decimal', 'numeric', 'float', 'double', 'real',
            'double precision', 'money',
        ], true)) {
            return 'numeric';
        }

        if (in_array($type, [
            'char', 'varchar', 'tinytext', 'text', 'mediumtext',
            'longtext', 'enum', 'set', 'character varying',
            'character', 'citext',
        ], true)) {
            return 'string';
        }

        if (in_array($type, [
            'date', 'datetime', 'timestamp', 'time', 'year',
            'timestamp with time zone', 'timestamp without time zone',
            'time with time zone', 'time without time zone',
        ], true)) {
            return 'date_or_time';
        }

        if (in_array($type, [
            'binary', 'varbinary', 'tinyblob', 'blob',
            'mediumblob', 'longblob', 'bytea',
        ], true)) {
            return 'binary';
        }

        if (in_array($type, ['bool', 'boolean'], true)) {
            return 'boolean';
        }

        if (in_array($type, ['json', 'jsonb'], true)) {
            return 'json';
        }

        return 'other';
    }
}
