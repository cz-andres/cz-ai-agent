<?php
declare(strict_types=1);

namespace CzAiAgent\Tests\Unit\Database;

use CzAiAgent\Database\SchemaFingerprint;
use PHPUnit\Framework\TestCase;

final class SchemaFingerprintTest extends TestCase
{
    public function testFingerprintIsStableWhenMetadataOrderChanges(): void
    {
        $fingerprint = new SchemaFingerprint();

        $first = [
            'database' => 'test_database',
            'tables' => [
                ['name' => 'table_b'],
                ['name' => 'table_a'],
            ],
            'columns' => [
                ['table_name' => 'table_a', 'name' => 'column_b'],
                ['table_name' => 'table_a', 'name' => 'column_a'],
            ],
            'foreign_keys' => [],
        ];

        $second = [
            'foreign_keys' => [],
            'columns' => [
                ['name' => 'column_a', 'table_name' => 'table_a'],
                ['name' => 'column_b', 'table_name' => 'table_a'],
            ],
            'tables' => [
                ['name' => 'table_a'],
                ['name' => 'table_b'],
            ],
            'database' => 'test_database',
        ];

        self::assertSame(
            $fingerprint->calculate($first),
            $fingerprint->calculate($second),
        );
    }

    public function testFingerprintChangesWhenSchemaChanges(): void
    {
        $fingerprint = new SchemaFingerprint();

        $original = [
            'database' => 'test_database',
            'tables' => [['name' => 'table_a']],
            'columns' => [],
            'foreign_keys' => [],
        ];

        $changed = $original;
        $changed['tables'][] = ['name' => 'table_b'];

        self::assertNotSame(
            $fingerprint->calculate($original),
            $fingerprint->calculate($changed),
        );
    }
}
