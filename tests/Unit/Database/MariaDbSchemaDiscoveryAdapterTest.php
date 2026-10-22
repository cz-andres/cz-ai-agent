<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\Discovery\MariaDbSchemaDiscoveryAdapter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class MariaDbSchemaDiscoveryAdapterTest extends TestCase
{
    public function testDiscoversOrderedIndexesAndCompositeForeignKeys(): void
    {
        $responses = [
            'information_schema.TABLES' => [
                [
                    'name' => 'accounts',
                    'type' => 'BASE TABLE',
                    'comment' => '',
                ],
                [
                    'name' => 'account_links',
                    'type' => 'BASE TABLE',
                    'comment' => '',
                ],
            ],
            'information_schema.COLUMNS' => [
                $this->rawColumn('accounts', 'tenant_id', 1, 'int', 'PRI'),
                $this->rawColumn('accounts', 'account_id', 2, 'int', 'PRI'),
                $this->rawColumn('accounts', 'email', 3, 'varchar', 'UNI'),
                $this->rawColumn('account_links', 'tenant_id', 1, 'int', 'MUL'),
                $this->rawColumn('account_links', 'account_id', 2, 'int', 'MUL'),
            ],
            'information_schema.STATISTICS' => [
                $this->rawIndex('accounts', 'PRIMARY', 0, 1, 'tenant_id'),
                $this->rawIndex('accounts', 'PRIMARY', 0, 2, 'account_id'),
                $this->rawIndex('accounts', 'uq_accounts_email', 0, 1, 'email'),
                $this->rawIndex('account_links', 'idx_account', 1, 1, 'tenant_id'),
                $this->rawIndex('account_links', 'idx_account', 1, 2, 'account_id'),
            ],
            'information_schema.KEY_COLUMN_USAGE' => [
                [
                    'name' => 'fk_account_links_account',
                    'ordinal_position' => 1,
                    'source_table' => 'account_links',
                    'source_column' => 'tenant_id',
                    'target_schema' => 'db',
                    'target_table' => 'accounts',
                    'target_column' => 'tenant_id',
                ],
                [
                    'name' => 'fk_account_links_account',
                    'ordinal_position' => 2,
                    'source_table' => 'account_links',
                    'source_column' => 'account_id',
                    'target_schema' => 'db',
                    'target_table' => 'accounts',
                    'target_column' => 'account_id',
                ],
            ],
        ];

        $pdo = new class($responses) extends PDO {
            public function __construct(private readonly array $responses)
            {
            }

            public function prepare(
                string $query,
                array $options = [],
            ): PDOStatement|false {
                foreach ($this->responses as $table => $rows) {
                    if (str_contains($query, $table)) {
                        return new class($rows) extends PDOStatement {
                            public function __construct(
                                private readonly array $rows,
                            ) {
                            }

                            public function execute(
                                ?array $params = null,
                            ): bool {
                                return true;
                            }

                            public function fetchAll(
                                int $mode = PDO::FETCH_DEFAULT,
                                mixed ...$args,
                            ): array {
                                return $this->rows;
                            }
                        };
                    }
                }

                self::fail('Unexpected schema metadata query.');
            }
        };

        $schema = (new MariaDbSchemaDiscoveryAdapter($pdo, 'db'))->discover();

        self::assertCount(3, $schema['indexes']);
        self::assertSame(
            ['tenant_id', 'account_id'],
            array_column($schema['indexes'][0]['columns'], 'name'),
        );
        self::assertFalse(
            $schema['columns'][0]['is_unique'],
            'Members of a composite primary key are not individually unique.',
        );
        self::assertTrue($schema['columns'][2]['is_unique']);
        self::assertCount(2, $schema['foreign_keys']);
        self::assertCount(1, $schema['foreign_key_constraints']);
        self::assertSame(
            ['tenant_id', 'account_id'],
            array_column(
                $schema['foreign_key_constraints'][0]['columns'],
                'source_column',
            ),
        );
    }

    private function rawColumn(
        string $table,
        string $name,
        int $ordinal,
        string $type,
        string $key,
    ): array {
        return [
            'table_name' => $table,
            'name' => $name,
            'ordinal_position' => $ordinal,
            'data_type' => $type,
            'native_type' => $type,
            'nullable' => 'NO',
            'column_default' => null,
            'column_key' => $key,
            'extra' => '',
            'comment' => '',
        ];
    }

    private function rawIndex(
        string $table,
        string $name,
        int $nonUnique,
        int $ordinal,
        string $column,
    ): array {
        return [
            'table_name' => $table,
            'name' => $name,
            'non_unique' => $nonUnique,
            'ordinal_position' => $ordinal,
            'column_name' => $column,
            'prefix_length' => null,
            'type' => 'BTREE',
        ];
    }
}
