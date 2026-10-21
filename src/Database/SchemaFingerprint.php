<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

final class SchemaFingerprint
{
    /**
     * @param array<string, mixed> $schema
     */
    public function calculate(array $schema): string
    {
        $canonical = $this->canonicalise($schema);

        return hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR),
        );
    }

    private function canonicalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $value = array_map(
                fn (mixed $item): mixed => $this->canonicalise($item),
                $value,
            );

            if (
                $value !== []
                && count(array_filter($value, 'is_array')) === count($value)
            ) {
                usort(
                    $value,
                    static fn (array $left, array $right): int =>
                    strcmp(
                        json_encode($left, JSON_THROW_ON_ERROR),
                        json_encode($right, JSON_THROW_ON_ERROR),
                        ),
                );
            }

            return $value;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalise($item);
        }

        return $value;
    }
}
