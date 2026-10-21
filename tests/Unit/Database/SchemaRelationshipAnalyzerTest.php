<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\SchemaRelationshipAnalyzer;
use PHPUnit\Framework\TestCase;

final class SchemaRelationshipAnalyzerTest extends TestCase
{
    public function testRecognisesDeclaredForeignKey(): void
    {
        $schema = $this->schema(
            tables: ['orders', 'customers'],
            columns: [
                $this->column('orders', 'customer_id', 'int'),
                $this->column('customers', 'id', 'int', true),
            ],
            foreignKeys: [
                [
                    'name' => 'fk_orders_customers',
                    'source_table' => 'orders',
                    'source_column' => 'customer_id',
                    'target_schema' => 'test_database',
                    'target_table' => 'customers',
                    'target_column' => 'id',
                ],
            ],
        );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertCount(1, $result['declared_relationships']);
        self::assertSame(
            'declared_foreign_key',
            $result['declared_relationships'][0]['status'],
        );
        self::assertSame([], $result['inferred_candidates']);
    }

    public function testInfersCandidateFromColumnAndTableNames(): void
    {
        $schema = $this->schema(
            tables: ['orders', 'customers'],
            columns: [
                $this->column('orders', 'customer_id', 'int'),
                $this->column('customers', 'id', 'int', true),
            ],
        );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertCount(1, $result['inferred_candidates']);

        $candidate = $result['inferred_candidates'][0];

        self::assertSame('orders', $candidate['source_table']);
        self::assertSame('customer_id', $candidate['source_column']);
        self::assertSame('customers', $candidate['target_table']);
        self::assertSame('id', $candidate['target_column']);
        self::assertTrue($candidate['type_compatible']);
        self::assertTrue($candidate['target_column_is_primary_key']);
        self::assertSame('inferred_unverified', $candidate['status']);
    }

    public function testFlagsIncompatibleTypeFamilies(): void
    {
        $schema = $this->schema(
            tables: ['orders', 'customers'],
            columns: [
                $this->column('orders', 'customer_id', 'varchar'),
                $this->column('customers', 'id', 'bigint', true),
            ],
        );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertCount(1, $result['inferred_candidates']);
        self::assertFalse(
            $result['inferred_candidates'][0]['type_compatible'],
        );
        self::assertSame(
            'string',
            $result['inferred_candidates'][0]['source_type_family'],
        );
        self::assertSame(
            'integer',
            $result['inferred_candidates'][0]['target_type_family'],
        );
    }

    public function testDoesNotInferRelationshipWithoutMatchingTarget(): void
    {
        $schema = $this->schema(
            tables: ['orders', 'products'],
            columns: [
                $this->column('orders', 'customer_id', 'int'),
                $this->column('products', 'id', 'int', true),
            ],
        );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertSame([], $result['declared_relationships']);
        self::assertSame([], $result['inferred_candidates']);
    }

    public function testDoesNotInferGenericAttributeColumnAsRelationship(): void
    {
        $schema = $this->schema(
            tables: ['authorised_rep_values', 'instance_variables'],
            columns: [
                $this->column('authorised_rep_values', 'attribute', 'varchar'),
                $this->column('instance_variables', 'attribute', 'varchar', true),
            ],
    );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertSame([], $result['inferred_candidates']);
    }

    public function testDoesNotInferSameNamedKeyForUnrelatedTable(): void
    {
        $schema = $this->schema(
            tables: ['claims', 'audit_logs'],
            columns: [
                $this->column('claims', 'client_ref', 'varchar'),
                $this->column('audit_logs', 'client_ref', 'varchar', true),
            ],
    );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertSame([], $result['inferred_candidates']);
    }

    public function testPrefersMatchingTargetKeyWhenTableHasMultipleKeys(): void
    {
        $schema = $this->schema(
            tables: ['access_types', 'question_categories'],
            columns: [
                $this->column('access_types', 'category_id', 'int'),
                $this->column('question_categories', 'category_id', 'int', true),
                $this->column('question_categories', 'question_id', 'int', true),
            ],
    );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertCount(1, $result['inferred_candidates']);
        self::assertSame(
            'category_id',
            $result['inferred_candidates'][0]['target_column'],
    );
        self::assertContains(
            'column_name_matches_target_key',
            $result['inferred_candidates'][0]['evidence'],
    );
    }


    public function testInfersInteriorEntityWhenTargetKeyNameMatches(): void
    {
        $schema = $this->schema(
            tables: ['question_actions', 'question_categories'],
            columns: [
                $this->column('question_actions', 'question_id', 'int'),
                $this->column('question_categories', 'question_id', 'int', true),
                $this->column('question_categories', 'category_id', 'int', true),
            ],
    );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertCount(1, $result['inferred_candidates']);
        self::assertSame(
            'question_id',
            $result['inferred_candidates'][0]['target_column'],
    );
    }

    public function testRejectsInteriorEntityWhenTargetKeyNameDoesNotMatch(): void
    {
        $schema = $this->schema(
            tables: ['user_permissions', 'permission_exclusions'],
            columns: [
                $this->column('user_permissions', 'permission_id', 'int'),
                $this->column('permission_exclusions', 'id', 'int', true),
            ],
    );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertSame([], $result['inferred_candidates']);
    }

    private function schema(
        array $tables,
        array $columns,
        array $foreignKeys = [],
    ): array {
        return [
            'database' => 'test_database',
            'tables' => array_map(
                static fn (string $name): array => [
                    'name' => $name,
                    'type' => 'BASE TABLE',
                    'comment' => '',
                ],
                $tables,
            ),
            'columns' => $columns,
            'foreign_keys' => $foreignKeys,
        ];
    }

    private function column(
        string $table,
        string $name,
        string $dataType,
        bool $primaryKey = false,
    ): array {
        return [
            'table_name' => $table,
            'name' => $name,
            'ordinal_position' => 1,
            'data_type' => $dataType,
            'native_type' => $dataType,
            'nullable' => !$primaryKey,
            'default' => null,
            'is_primary_key' => $primaryKey,
            'is_indexed' => $primaryKey,
            'is_unique' => $primaryKey,
            'auto_increment' => false,
            'comment' => '',
        ];
    }

    public function testInfersRelationshipFromMatchingKeyColumnNames(): void
    {
        $schema = $this->schema(
            tables: ['claims', 'clients'],
            columns: [
                $this->column('claims', 'client_ref', 'varchar'),
                $this->column('clients', 'client_ref', 'varchar', true),
            ],
    );

        $result = (new SchemaRelationshipAnalyzer())->analyze($schema);

        self::assertCount(1, $result['inferred_candidates']);

        $candidate = $result['inferred_candidates'][0];

        self::assertSame('claims', $candidate['source_table']);
        self::assertSame('clients', $candidate['target_table']);
        self::assertSame('client_ref', $candidate['target_column']);
        self::assertContains(
            'column_name_matches_target_key',
            $candidate['evidence'],
    );
    }
}