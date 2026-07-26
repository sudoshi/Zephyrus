<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

final class IsolatedTestDatabase
{
    /**
     * A test runner may only terminate its own sessions. Terminating every
     * connection to a test database can fail when an administrator, monitor,
     * or backup process owns a session, which would leave the isolated
     * database behind even though the runner has no authority over that
     * other process.
     */
    public const TERMINATE_OWNED_CONNECTIONS_QUERY =
        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '
        .'WHERE datname = :database AND pid <> pg_backend_pid() AND usename = current_user';

    private static ?string $database = null;

    public static function provision(): void
    {
        if (self::$database !== null) {
            return;
        }

        $configured = (string) (getenv('DB_DATABASE') ?: '');
        if (! preg_match('/^zephyrus_test(?:_[a-z0-9]+)?$/', $configured)) {
            throw new RuntimeException(
                'Refusing to provision a PHPUnit database because DB_DATABASE is not test-scoped.',
            );
        }

        $token = substr(hash('sha256', getcwd().'|'.getmypid().'|'.bin2hex(random_bytes(16))), 0, 12);
        $database = 'zephyrus_test_'.$token;
        $admin = self::adminConnection();

        $admin->exec('CREATE DATABASE '.self::identifier($database));
        self::$database = $database;
        self::setEnvironment('DB_DATABASE', $database);

        register_shutdown_function(static function (): void {
            self::drop();
        });
    }

    public static function database(): ?string
    {
        return self::$database;
    }

    /**
     * Drop every non-system schema in the CURRENT test database and
     * recreate an empty public schema — the same state a freshly
     * provisioned database presents (migrations recreate all schemas and
     * extensions via CREATE ... IF NOT EXISTS). Needed because
     * migrate:fresh only drops search_path tables and this application
     * spans many PG schemas. Used by the CI plan S2 boundary guard in
     * tests/TestCase.php when a non-scenario class follows a class-scoped
     * committed scenario in the same process.
     */
    public static function resetAllSchemas(): void
    {
        $database = (string) (getenv('DB_DATABASE') ?: '');
        if (! preg_match('/^zephyrus_test(?:_[a-z0-9]+)?$/', $database)) {
            throw new RuntimeException(
                'Refusing to reset schemas because DB_DATABASE is not test-scoped.',
            );
        }

        $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('DB_PORT') ?: '5432');
        $username = (string) (getenv('DB_USERNAME') ?: 'postgres');
        $password = (string) (getenv('DB_PASSWORD') ?: '');

        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname={$database}",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $schemas = $pdo->query(
            "SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg\\_%' AND nspname <> 'information_schema'",
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($schemas as $schema) {
            $pdo->exec('DROP SCHEMA '.self::identifier((string) $schema).' CASCADE');
        }

        $pdo->exec('CREATE SCHEMA public');
    }

    private static function drop(): void
    {
        $database = self::$database;
        self::$database = null;

        if ($database === null || ! preg_match('/^zephyrus_test_[a-f0-9]{12}$/', $database)) {
            return;
        }

        try {
            $admin = self::adminConnection();
            $statement = $admin->prepare(self::TERMINATE_OWNED_CONNECTIONS_QUERY);
            $statement->execute(['database' => $database]);
            $admin->exec('DROP DATABASE IF EXISTS '.self::identifier($database));
        } catch (\Throwable $exception) {
            fwrite(STDERR, "Unable to remove isolated PHPUnit database {$database}: {$exception->getMessage()}\n");
        }
    }

    private static function adminConnection(): PDO
    {
        $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
        $port = (string) (getenv('DB_PORT') ?: '5432');
        $database = (string) (getenv('TEST_DB_ADMIN_DATABASE') ?: 'postgres');
        $username = (string) (getenv('DB_USERNAME') ?: 'postgres');
        $password = (string) (getenv('DB_PASSWORD') ?: '');

        try {
            return new PDO(
                "pgsql:host={$host};port={$port};dbname={$database}",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Unable to connect to the PostgreSQL maintenance database for isolated PHPUnit provisioning.',
                previous: $exception,
            );
        }
    }

    private static function identifier(string $value): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $value)) {
            throw new RuntimeException('Unsafe PostgreSQL database identifier.');
        }

        return '"'.$value.'"';
    }

    private static function setEnvironment(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
