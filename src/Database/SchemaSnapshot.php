<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

final class SchemaSnapshot
{
    /**
     * @param list<array{
     *     name: string,
     *     type: string,
     *     comment: string
     * }> $tables
     * @param list<array{
     *     table: string,
     *     name: string,
     *     ordinal_position: int,
     *     data_type: string,
     *     native_type: string,
     *     nullable: bool,
     *     default: mixed,
     *     primary_key: bool,
     *     indexed: bool,
     *     unique: bool,
     *     extra: string,
     *     comment: string
     * }> $columns
     * @param list<array{
     *     table: string,
     *     column: string,
     *     constraint: string,
     *     referenced_schema: string,
     *     referenced_table: string,
     *     referenced_column: string,
     *     ordinal_position: int
     * }> $foreignKeys
     */
    public function __construct(
        public readonly string $database,
        public readonly array $tables,
        public readonly array $columns,
        public readonly array $foreignKeys,
    ) {
    }
}
