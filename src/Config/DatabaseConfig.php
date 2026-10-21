<?php
declare(strict_types=1);

namespace CzAiAgent\Config;

use RuntimeException;

final class DatabaseConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $required = [
            'DB_HOST',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
        ];

        $values = [];

        foreach ($required as $key) {
            $value = getenv($key);

            if ($value === false || trim($value) === '') {
                throw new RuntimeException(
                    "Missing required database configuration: {$key}"
                );
            }

            $values[$key] = $value;
        }

        $port = getenv('DB_PORT');

        if ($port === false || trim($port) === '') {
            $port = '3306';
        }

        if (
            !ctype_digit($port)
            || (int) $port < 1
            || (int) $port > 65535
        ) {
            throw new RuntimeException(
                'DB_PORT must be a valid TCP port.'
            );
        }

        return new self(
            host: trim($values['DB_HOST']),
            port: (int) $port,
            database: trim($values['DB_DATABASE']),
            username: trim($values['DB_USERNAME']),
            password: $values['DB_PASSWORD'],
        );
    }
}
