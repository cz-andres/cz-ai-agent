<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

final class KnowledgeBaseLoader
{
    public function __construct(
        private readonly KnowledgeBaseStore $store,
        private readonly SchemaFingerprint $fingerprint = new SchemaFingerprint(),
    ) {
    }

    /**
     * @param array<string, mixed>|null $currentSchema
     * @return array{
     *     snapshot: array<string, mixed>,
     *     schema_status: string
     * }
     */
    public function load(?array $currentSchema = null): array
    {
        $snapshot = $this->store->load();

        if ($currentSchema === null) {
            return [
                'snapshot' => $snapshot,
                'schema_status' => 'not_checked',
            ];
        }

        $currentFingerprint = $this->fingerprint->calculate(
            $currentSchema,
        );

        return [
            'snapshot' => $snapshot,
            'schema_status' =>
                hash_equals(
                    $snapshot['source']['schema_fingerprint'],
                    $currentFingerprint,
                )
                    ? 'current'
                    : 'stale',
        ];
    }
}
