<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Database;

use CzAiAgent\Database\Discovery\SchemaDiscoveryAdapter;
use CzAiAgent\Database\Discovery\SchemaDiscoveryService;
use PHPUnit\Framework\TestCase;

final class SchemaDiscoveryServiceTest extends TestCase
{
    public function testDelegatesSchemaDiscoveryToAdapter(): void
    {
        $expectedSchema = [
            'database' => 'test_database',
            'tables' => [
                [
                    'table_name' => 'customers',
                    'table_type' => 'BASE TABLE',
                ],
            ],
            'columns' => [],
            'foreign_keys' => [],
        ];

        $adapter = new class ($expectedSchema) implements SchemaDiscoveryAdapter {
            public int $calls = 0;

            public function __construct(
                private readonly array $schema,
            ) {
            }

            public function discover(): array
            {
                $this->calls++;

                return $this->schema;
            }
        };

        $service = new SchemaDiscoveryService($adapter);

        $actualSchema = $service->discover();

        self::assertSame($expectedSchema, $actualSchema);
        self::assertSame(1, $adapter->calls);
    }
}