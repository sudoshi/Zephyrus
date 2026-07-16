<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
Dotenv::createImmutable($projectRoot)->safeLoad();

$operation = $argv[1] ?? '';
$database = $argv[2] ?? '';

if (! in_array($operation, ['create', 'drop', 'list-orphans'], true)) {
    fwrite(STDERR, "Usage: php scripts/manage-test-database.php create|drop zephyrus_test_e2e<12 hex> | list-orphans\n");
    exit(64);
}

if ($operation !== 'list-orphans' && ! preg_match('/^zephyrus_test_e2e[a-f0-9]{12}$/', $database)) {
    fwrite(STDERR, "Refusing to manage a database outside the browser-test namespace.\n");
    exit(64);
}

if ($operation === 'list-orphans' && $database !== '') {
    fwrite(STDERR, "list-orphans does not accept a database name.\n");
    exit(64);
}

/** @return string */
$environment = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    return (string) ($_ENV[$key] ?? $_SERVER[$key] ?? $default);
};

$host = $environment('DB_HOST', '127.0.0.1');
$port = $environment('DB_PORT', '5432');
$adminDatabase = $environment('TEST_DB_ADMIN_DATABASE', 'postgres');
$username = $environment('DB_USERNAME', 'postgres');
$password = $environment('DB_PASSWORD');

if (! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "Refusing to manage a test database through a non-loopback PostgreSQL host.\n");
    exit(64);
}

if (! in_array($adminDatabase, ['postgres', 'template1'], true)) {
    fwrite(STDERR, "TEST_DB_ADMIN_DATABASE must be postgres or template1.\n");
    exit(64);
}

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$adminDatabase}",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    if ($operation === 'list-orphans') {
        $statement = $pdo->prepare('SELECT datname FROM pg_database WHERE datname LIKE :pattern ORDER BY datname');
        $statement->execute(['pattern' => 'zephyrus_test_%']);
        $orphans = array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_COLUMN),
            static fn (mixed $name): bool => is_string($name)
                && preg_match('/^zephyrus_test_(?:e2e)?[a-f0-9]{12}$/', $name) === 1,
        ));
        fwrite(STDOUT, json_encode($orphans, JSON_THROW_ON_ERROR).PHP_EOL);
        exit(0);
    }

    $identifier = '"'.$database.'"';
    if ($operation === 'create') {
        $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
        $statement->execute(['database' => $database]);
        if ($statement->fetchColumn() !== false) {
            throw new RuntimeException("Browser test database {$database} already exists.");
        }

        $pdo->exec("CREATE DATABASE {$identifier}");
        fwrite(STDOUT, "Created browser test database {$database}.\n");
        exit(0);
    }

    $statement = $pdo->prepare(
        'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()',
    );
    $statement->execute(['database' => $database]);
    $pdo->exec("DROP DATABASE IF EXISTS {$identifier}");
    fwrite(STDOUT, "Removed browser test database {$database}.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Unable to {$operation} browser test database {$database}: {$exception->getMessage()}\n");
    exit(1);
}
