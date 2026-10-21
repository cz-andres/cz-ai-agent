<?php
declare(strict_types=1);

namespace CzAiAgent\Database;

use CzAiAgent\Config\DatabaseConfig;
use PDO;
use RuntimeException;
use Throwable;

final class ReadOnlyDatabaseConnectionFactory
{
    private const CONNECTION_TIMEOUT_SECONDS = 5;
    private const QUERY_TIMEOUT_SECONDS = 5;

    public static function connect(?DatabaseConfig $config = null): PDO
    {
        $config ??= DatabaseConfig::fromEnvironment();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->host,
            $config->port,
            $config->database,
        );

        try {
            $pdo = new PDO(
                $dsn,
                $config->username,
                $config->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::ATTR_TIMEOUT => self::CONNECTION_TIMEOUT_SECONDS,
                ],
            );

            // MariaDB aborts statements that exceed this session limit.
            $pdo->exec(
                'SET SESSION max_statement_time = '
                . self::QUERY_TIMEOUT_SECONDS
            );

            return $pdo;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Could not establish the restricted database connection.',
                0,
                $exception,
            );
        }
    }
}
