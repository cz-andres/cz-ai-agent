<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\SchemaContextRetriever;
use CzAiAgent\Database\SchemaDiscoveryAdapter;
use CzAiAgent\Database\SchemaDiscoveryService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SchemaContextRetrieverTest extends TestCase
{
    public function testRetrievesRelevantTablesColumnsAndDeclaredRelationships(): void
    {
        $retriever = $this->createRetriever();

        $context = $retriever->retrieve(
            'Show policy premium amounts',
            maxTables: 3,
        );

        self::assertSame('test_db', $context['database']);
        self::assertGreaterThan(0, $context['matched_table_count']);

        $tableNames = array_column($context['tables'], 'name');

        self::assertContains('policies', $tableNames);
        self::assertContains('customers', $tableNames);
        self::assertNotEmpty($context['relationships']);

        $policyTable = array_values(array_filter(
            $context['tables'],
            static fn (array $table): bool => $table['name'] === 'policies'
        ))[0];

        $columnNames = array_column($policyTable['columns'], 'name');

        self::assertContains('premium_amount', $columnNames);
        self::assertLessThanOrEqual(3, count($context['tables']));
    }

    public function testRejectsAnEmptyQuestion(): void
    {
        $retriever = $this->createRetriever();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The schema context question cannot be empty.'
        );

        $retriever->retrieve('   ');
    }

    public function testRejectsInvalidTableLimit(): void
    {
        $retriever = $this->createRetriever();

        $this->expectException(InvalidArgumentException::class);
        $retriever->retrieve('policy premium', maxTables: 0);
    }

    public function testCapsReturnedColumnsPerTable(): void
    {
        $retriever = $this->createRetriever();

        $context = $retriever->retrieve(
            'policy premium amount customer name',
            maxTables: 5,
            maxColumnsPerTable: 2,
        );

        foreach ($context['tables'] as $table) {
            self::assertLessThanOrEqual(2, count($table['columns']));
        }
    }

    private function createRetriever(): SchemaContextRetriever
    {
        $schema = [
            'database' => 'test_db',
            'tables' => [
                [
                    'name' => 'policies',
                    'type' => 'BASE TABLE',
                    'comment' => 'Insurance policies',
                ],
                [
                    'name' => 'customers',
                    'type' => 'BASE TABLE',
                    'comment' => 'Customer records',
                ],
                [
                    'name' => 'audit_logs',
                    'type' => 'BASE TABLE',
                    'comment' => 'System audit history',
                ],
            ],
            'columns' => [
                [
                    'table_name' => 'policies',
                    'name' => 'id',
                    'data_type' => 'bigint',
                    'comment' => '',
                    'is_primary_key' => true,
                    'is_indexed' => true,
                ],
                [
                    'table_name' => 'policies',
                    'name' => 'customer_id',
                    'data_type' => 'bigint',
                    'comment' => 'Related customer',
                    'is_primary_key' => false,
                    'is_indexed' => true,
                ],
                [
                    'table_name' => 'policies',
                    'name' => 'premium_amount',
                    'data_type' => 'decimal',
                    'comment' => 'Policy premium amount',
                    'is_primary_key' => false,
                    'is_indexed' => false,
                ],
                [
                    'table_name' => 'customers',
                    'name' => 'id',
                    'data_type' => 'bigint',
                    'comment' => '',
                    'is_primary_key' => true,
                    'is_indexed' => true,
                ],
                [
                    'table_name' => 'customers',
                    'name' => 'name',
                    'data_type' => 'varchar',
                    'comment' => 'Customer name',
                    'is_primary_key' => false,
                    'is_indexed' => false,
                ],
                [
                    'table_name' => 'audit_logs',
                    'name' => 'event_payload',
                    'data_type' => 'text',
                    'comment' => 'Audit event payload',
                    'is_primary_key' => false,
                    'is_indexed' => false,
                ],
            ],
            'foreign_keys' => [
                [
                    'name' => 'fk_policy_customer',
                    'source_table' => 'policies',
                    'source_column' => 'customer_id',
                    'target_schema' => 'test_db',
                    'target_table' => 'customers',
                    'target_column' => 'id',
                ],
            ],
        ];

        $adapter = new class ($schema) implements SchemaDiscoveryAdapter {
            public function __construct(private readonly array $schema)
            {
            }

            public function discover(): array
            {
                return $this->schema;
            }
        };

        return new SchemaContextRetriever(
            new SchemaDiscoveryService($adapter)
        );
    }
}
