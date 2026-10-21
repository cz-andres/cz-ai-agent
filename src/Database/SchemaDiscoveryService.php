<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

final class SchemaDiscoveryService
{
    public function __construct(
        private readonly SchemaDiscoveryAdapter $adapter,
    ) {
    }

    /**
     * @return array{
     *     database: string,
     *     tables: list<array<string, mixed>>,
     *     columns: list<array<string, mixed>>,
     *     foreign_keys: list<array<string, mixed>>
     * }
     */
    public function discover(): array
    {
        return $this->adapter->discover();
    }
}
