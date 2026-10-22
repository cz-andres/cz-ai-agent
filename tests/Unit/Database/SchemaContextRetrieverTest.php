<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\Context\SchemaContextRetriever;
use CzAiAgent\Database\Discovery\SchemaDiscoveryAdapter;
use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
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

    public function testIncludesJoinColumnsEvenWhenColumnLimitIsReached(): void
    {
        $context = $this->createRetriever()->retrieve(
            'policy premium',
            maxTables: 2,
            maxColumnsPerTable: 1,
        );

        $tables = [];
        foreach ($context['tables'] as $table) {
            $tables[$table['name']] = array_column($table['columns'], 'name');
        }

        self::assertContains('policies', array_keys($tables));
        self::assertContains('customers', array_keys($tables));
        self::assertContains('customer_id', $tables['policies']);
        self::assertContains('id', $tables['customers']);
        self::assertNotEmpty($context['relationships']);
    }

    public function testReportsLowConfidenceWhenNoSchemaTermsMatch(): void
    {
        $context = $this->createRetriever()->retrieve(
            'quasar nebula chronometer',
        );

        self::assertSame(0, $context['matched_table_count']);
        self::assertSame('low', $context['retrieval_confidence']);
        self::assertNotEmpty($context['warnings']);
        self::assertSame([], $context['tables']);
    }

    public function testMarksRelationshipAddedTablesAsNeighbours(): void
    {
        $context = $this->createRetriever()->retrieve(
            'policy premium',
            maxTables: 2,
        );

        $customers = array_values(array_filter(
            $context['tables'],
            static fn (array $table): bool => $table['name'] === 'customers'
        ));

        self::assertCount(1, $customers);
        self::assertSame(
            'declared_relationship_neighbour',
            $customers[0]['selection_reason']
        );
    }

    public function testExactColumnNamesArePrioritizedAndMarkedInContext(): void
    {
        $context = $this->createRetriever()->retrieve(
            'give me premium_amount',
        );

        $policies = array_values(array_filter(
            $context['tables'],
            static fn (array $table): bool => $table['name'] === 'policies'
        ))[0];
        $premium = array_values(array_filter(
            $policies['columns'],
            static fn (array $column): bool => $column['name'] === 'premium_amount'
        ))[0];

        self::assertTrue($premium['exact_question_match']);
    }

    public function testBuildsQualifiedFieldCatalogForCrossTableQuestion(): void
    {
        $context = $this->createRetriever([
            'database' => 'test_db',
            'tables' => [
                ['name' => 'n_policy_premium_figures', 'comment' => 'Policy premium figures'],
                ['name' => 'n_policy_periods', 'comment' => 'Policy periods'],
                ['name' => 'n_policy_groups', 'comment' => 'Policy groups'],
            ],
            'columns' => [
                ['table_name' => 'n_policy_premium_figures', 'name' => 'base_premium'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'period_id'],
                ['table_name' => 'n_policy_periods', 'name' => 'id'],
                ['table_name' => 'n_policy_periods', 'name' => 'policy_id'],
                ['table_name' => 'n_policy_groups', 'name' => 'policy_id'],
            ],
            'foreign_keys' => [
                [
                    'source_table' => 'n_policy_premium_figures',
                    'source_column' => 'period_id',
                    'target_table' => 'n_policy_periods',
                    'target_column' => 'id',
                ],
            ],
        ])->retrieve(
            'give me the base_premium of policy_id 367838 period_id 723752',
        );

        $candidateIds = array_column($context['field_candidates'], 'id');
        self::assertContains(
            'n_policy_premium_figures.base_premium',
            $candidateIds,
        );
        self::assertContains(
            'n_policy_premium_figures.period_id',
            $candidateIds,
        );
        self::assertContains(
            'n_policy_periods.policy_id',
            $candidateIds,
        );
        self::assertContains(
            'n_policy_groups.policy_id',
            $candidateIds,
        );
        self::assertContains(
            'n_policy_premium_figures',
            array_column($context['candidate_relationships'], 'source_table'),
        );
    }

    public function testInsuranceGlossaryAddsCandidatesForPlainLanguageTerms(): void
    {
        $context = $this->createRetriever([
            'database' => 'test_db',
            'tables' => [
                ['name' => 'premium_records', 'comment' => ''],
            ],
            'columns' => [
                ['table_name' => 'premium_records', 'name' => 'base_premium'],
            ],
            'foreign_keys' => [],
        ])->retrieve('show base premium');

        self::assertSame(
            'premium_records.base_premium',
            $context['field_candidates'][0]['id'],
        );
        self::assertContains(
            'base premium',
            $context['field_candidates'][0]['matched_glossary_terms'],
        );
    }

    public function testResolvesNaturalPolicyNumberAndCurrentPeriodConceptCandidates(): void
    {
        $schema = [
            'database' => 'test_db',
            'tables' => [
                ['name' => 'n_policies', 'comment' => 'Insurance policies'],
                ['name' => 'n_policy_periods', 'comment' => 'Policy periods'],
                ['name' => 'n_policy_premium_figures', 'comment' => 'Premium figures'],
            ],
            'columns' => [
                ['table_name' => 'n_policies', 'name' => 'id', 'data_type' => 'int'],
                ['table_name' => 'n_policies', 'name' => 'policy_number', 'data_type' => 'varchar'],
                ['table_name' => 'n_policies', 'name' => 'current_period_id', 'data_type' => 'int'],
                ['table_name' => 'n_policy_periods', 'name' => 'id', 'data_type' => 'int'],
                ['table_name' => 'n_policy_periods', 'name' => 'policy_id', 'data_type' => 'int'],
                ['table_name' => 'n_policy_periods', 'name' => 'expiry_date', 'data_type' => 'date'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'period_id', 'data_type' => 'int'],
                ['table_name' => 'n_policy_premium_figures', 'name' => 'base_premium', 'data_type' => 'decimal'],
            ],
            'foreign_keys' => [
                [
                    'source_table' => 'n_policies',
                    'source_column' => 'current_period_id',
                    'target_table' => 'n_policy_periods',
                    'target_column' => 'id',
                ],
                [
                    'source_table' => 'n_policy_periods',
                    'source_column' => 'policy_id',
                    'target_table' => 'n_policies',
                    'target_column' => 'id',
                ],
                [
                    'source_table' => 'n_policy_premium_figures',
                    'source_column' => 'period_id',
                    'target_table' => 'n_policy_periods',
                    'target_column' => 'id',
                ],
            ],
        ];
        $retriever = $this->createRetriever($schema);

        $policyNumberContext = $retriever->retrieve(
            'show me the premium of policy LP123456',
        );
        $policyNumber = array_values(array_filter(
            $policyNumberContext['field_candidates'],
            static fn (array $candidate): bool =>
                $candidate['id'] === 'n_policies.policy_number'
        ))[0];
        self::assertContains('policy_number', $policyNumber['canonical_concepts']);

        $currentPeriodContext = $retriever->retrieve(
            'show me the current period for policy id 14535342',
        );
        $currentPeriod = array_values(array_filter(
            $currentPeriodContext['field_candidates'],
            static fn (array $candidate): bool =>
                $candidate['id'] === 'n_policies.current_period_id'
        ))[0];
        self::assertContains('current_period_id', $currentPeriod['canonical_concepts']);

        $policyId = array_values(array_filter(
            $currentPeriodContext['field_candidates'],
            static fn (array $candidate): bool =>
                $candidate['id'] === 'n_policies.id'
        ))[0];
        self::assertContains('policy_id', $policyId['canonical_concepts']);
    }

    public function testDerivesCustomerIdentifierFromDeclaredForeignKeyAndCustomerPrimaryKey(): void
    {
        $context = $this->createRetriever([
            'database' => 'test_db',
            'tables' => [
                ['name' => 'policy_records', 'comment' => 'Insurance policies'],
                ['name' => 'customer_master', 'comment' => 'Customer records'],
            ],
            'columns' => [
                [
                    'table_name' => 'policy_records',
                    'name' => 'id',
                    'data_type' => 'int',
                    'is_primary_key' => true,
                ],
                [
                    'table_name' => 'policy_records',
                    'name' => 'customer_ref',
                    'data_type' => 'int',
                    'is_primary_key' => false,
                ],
                [
                    'table_name' => 'customer_master',
                    'name' => 'customer_key',
                    'data_type' => 'int',
                    'is_primary_key' => true,
                ],
                [
                    'table_name' => 'customer_master',
                    'name' => 'display_name',
                    'data_type' => 'varchar',
                    'is_primary_key' => false,
                ],
            ],
            'foreign_keys' => [
                [
                    'name' => 'fk_policy_customer',
                    'source_table' => 'policy_records',
                    'source_column' => 'customer_ref',
                    'target_table' => 'customer_master',
                    'target_column' => 'customer_key',
                ],
            ],
        ])->retrieve('show policies for customer id 00071');

        $byId = [];
        foreach ($context['field_candidates'] as $candidate) {
            $byId[$candidate['id']] = $candidate;
        }

        self::assertContains(
            'customer_id',
            $byId['customer_master.customer_key']['canonical_concepts'],
        );
        self::assertContains(
            'customer_id',
            $byId['policy_records.customer_ref']['concepts'],
        );
        self::assertArrayHasKey('customer_master.customer_key', $byId);
    }

    public function testIncludesDeclaredRelationshipBridgeBetweenRelevantTables(): void
    {
        $context = $this->createRetriever([
            'database' => 'test_db',
            'tables' => [
                ['name' => 'policy_records', 'comment' => ''],
                ['name' => 'coverage_links', 'comment' => ''],
                ['name' => 'period_records', 'comment' => 'Reporting periods'],
            ],
            'columns' => [
                ['table_name' => 'policy_records', 'name' => 'id'],
                ['table_name' => 'policy_records', 'name' => 'base_premium'],
                ['table_name' => 'coverage_links', 'name' => 'source_key'],
                ['table_name' => 'coverage_links', 'name' => 'target_key'],
                ['table_name' => 'period_records', 'name' => 'id'],
            ],
            'foreign_keys' => [
                [
                    'source_table' => 'coverage_links',
                    'source_column' => 'source_key',
                    'target_table' => 'policy_records',
                    'target_column' => 'id',
                ],
                [
                    'source_table' => 'coverage_links',
                    'source_column' => 'target_key',
                    'target_table' => 'period_records',
                    'target_column' => 'id',
                ],
            ],
        ])->retrieve(
            'base_premium for reporting period',
            maxTables: 3,
        );

        $tableNames = array_column($context['tables'], 'name');
        self::assertContains('policy_records', $tableNames);
        self::assertContains('period_records', $tableNames);
        self::assertContains('coverage_links', $tableNames);
        self::assertNotEmpty($context['relationships']);

        $bridge = array_values(array_filter(
            $context['tables'],
            static fn (array $table): bool => $table['name'] === 'coverage_links'
        ))[0];
        self::assertSame('declared_relationship_bridge', $bridge['selection_reason']);
        self::assertContains('source_key', array_column($bridge['columns'], 'name'));
        self::assertContains('target_key', array_column($bridge['columns'], 'name'));
    }

    private function createRetriever(?array $schema = null): SchemaContextRetriever
    {
        $schema ??= [
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
