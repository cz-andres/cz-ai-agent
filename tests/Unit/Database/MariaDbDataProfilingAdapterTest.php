<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\Profiling\MariaDbDataProfilingAdapter;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class MariaDbDataProfilingAdapterTest extends TestCase
{
    public function testRejectsSampleLimitAboveMaximum(): void
    {
        $adapter = new MariaDbDataProfilingAdapter(
            $this->createUninitialisedPdo(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sample limit must be between 1 and 5000.');

        $adapter->profileRelationship(
            $this->candidate(),
            $this->schema(),
            5001,
        );
    }

    public function testRejectsCandidateWithUnknownColumn(): void
    {
        $adapter = new MariaDbDataProfilingAdapter(
            $this->createUninitialisedPdo(),
        );

        $candidate = $this->candidate();
        $candidate['source_column'] = 'unknown_column';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The candidate references a table or column absent from the discovered schema.'
        );

        $adapter->profileRelationship($candidate, $this->schema());
    }

    public function testDoesNotQueryDatabaseForIncompatibleTypes(): void
    {
        $adapter = new MariaDbDataProfilingAdapter(
            $this->createUninitialisedPdo(),
        );

        $schema = $this->schema();
        $schema['columns'][1]['data_type'] = 'varchar';

        $result = $adapter->profileRelationship(
            $this->candidate(),
            $schema,
        );

        self::assertSame('incompatible_types', $result['status']);
        self::assertSame(0, $result['sampled_value_count']);
        self::assertSame(0, $result['matched_value_count']);
        self::assertNull($result['match_rate']);
    }

    public function testBuildsTupleComparisonForCompositeDeclaredForeignKey(): void
    {
        $pdo = new class extends PDO {
            public string $query = '';

            public function __construct()
            {
            }

            public function query(
                string $query,
                ?int $fetchMode = null,
                mixed ...$fetchModeArgs,
            ): PDOStatement|false {
                $this->query = $query;

                return new class extends PDOStatement {
                    public function __construct()
                    {
                    }

                    public function fetch(
                        int $mode = PDO::FETCH_DEFAULT,
                        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
                        int $cursorOffset = 0,
                    ): mixed {
                        return [
                            'sampled_value_count' => 4,
                            'matched_value_count' => 3,
                        ];
                    }
                };
            }
        };

        $schema = $this->schema();
        $schema['columns'] = [
            [
                'table_name' => 'claims',
                'name' => 'tenant_id',
                'data_type' => 'int',
            ],
            [
                'table_name' => 'claims',
                'name' => 'claim_id',
                'data_type' => 'int',
            ],
            [
                'table_name' => 'policies',
                'name' => 'tenant_id',
                'data_type' => 'int',
            ],
            [
                'table_name' => 'policies',
                'name' => 'policy_id',
                'data_type' => 'int',
            ],
        ];
        $candidate = [
            'source_table' => 'claims',
            'target_table' => 'policies',
            'status' => 'declared_foreign_key',
            'column_pairs' => [
                [
                    'source_column' => 'tenant_id',
                    'target_column' => 'tenant_id',
                    'ordinal_position' => 1,
                ],
                [
                    'source_column' => 'claim_id',
                    'target_column' => 'policy_id',
                    'ordinal_position' => 2,
                ],
            ],
        ];

        $result = (new MariaDbDataProfilingAdapter($pdo))->profileRelationship(
            $candidate,
            $schema,
            sampleLimit: 25,
        );

        self::assertSame('profiled', $result['status']);
        self::assertSame(0.75, $result['match_rate']);
        self::assertSame(25, $result['sample_limit']);
        self::assertStringContainsString(
            'SELECT `tenant_id` AS `source_value_0`, `claim_id` AS `source_value_1`',
            $pdo->query,
        );
        self::assertStringContainsString(
            'target_rows.`tenant_id` = source_values.`source_value_0` AND target_rows.`policy_id` = source_values.`source_value_1`',
            $pdo->query,
        );
    }

    private function createUninitialisedPdo(): PDO
    {
        return new class extends PDO {
            public function __construct()
            {
            }
        };
    }

    private function candidate(): array
    {
        return [
            'source_table' => 'claims',
            'source_column' => 'client_id',
            'target_table' => 'clients',
            'target_column' => 'id',
        ];
    }

    private function schema(): array
    {
        return [
            'database' => 'test_database',
            'tables' => [],
            'columns' => [
                [
                    'table_name' => 'claims',
                    'name' => 'client_id',
                    'data_type' => 'int',
                ],
                [
                    'table_name' => 'clients',
                    'name' => 'id',
                    'data_type' => 'int',
                ],
            ],
            'foreign_keys' => [],
        ];
    }
}