<?php
declare(strict_types=1);

namespace CzAiAgent\Database\Discovery;

interface SchemaDiscoveryAdapter
{
    /**
     * Return database metadata using the shared, engine-independent structure.
     *
     * @return array{
     *     database: string,
     *     tables: list<array{
     *         name: string,
     *         type: string,
     *         comment: string
     *     }>,
     *     columns: list<array{
     *         table_name: string,
     *         name: string,
     *         ordinal_position: int,
     *         data_type: string,
     *         native_type: string,
     *         nullable: bool,
     *         default: mixed,
     *         is_primary_key: bool,
     *         is_indexed: bool,
     *         is_unique: bool,
     *         auto_increment: bool,
     *         comment: string
     *     }>,
     *     indexes: list<array{
     *         table_name: string,
     *         name: string,
     *         is_unique: bool,
     *         type: string,
     *         columns: list<array{
     *             name: string|null,
     *             ordinal_position: int,
     *             prefix_length: int|null
     *         }>
     *     }>,
     *     foreign_keys: list<array{
     *         name: string,
     *         ordinal_position: int,
     *         source_table: string,
     *         source_column: string,
     *         target_schema: string,
     *         target_table: string,
     *         target_column: string
     *     }>,
     *     foreign_key_constraints: list<array{
     *         name: string,
     *         source_table: string,
     *         target_schema: string,
     *         target_table: string,
     *         columns: list<array{
     *             source_column: string,
     *             target_column: string,
     *             ordinal_position: int
     *         }>
     *     }>
     * }
     */
    public function discover(): array;
}