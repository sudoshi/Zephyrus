<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

final class IsolatedTestDatabase
{
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

    private static function drop(): void
    {
        $database = self::$database;
        self::$database = null;

        if ($database === null || ! preg_match('/^zephyrus_test_[a-f0-9]{12}$/', $database)) {
            return;
        }

        try {
            $admin = self::adminConnection();
            $statement = $admin->prepare(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()',
            );
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
