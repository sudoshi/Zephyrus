<?php

namespace App\Database;

use Illuminate\Database\Connection;
use RuntimeException;

final class ProductionDatabaseReadOnlyGuard
{
    private const PRODUCTION_HOST = 'pgsql.acumenus.net';

    private const PRODUCTION_USERNAME = 'smudoshi';

    public function protect(Connection $connection, string $environment): void
    {
        if (! $this->shouldProtect($connection->getConfig(), $environment)) {
            return;
        }

        $pdo = $connection->getPdo();
        $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');

        $readOnly = $pdo->query("SELECT current_setting('default_transaction_read_only')")
            ?->fetchColumn();

        if ($readOnly !== 'on') {
            throw new RuntimeException('production_database_read_only_guard_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function shouldProtect(array $configuration, string $environment): bool
    {
        if ($environment === 'production' || ($configuration['driver'] ?? null) !== 'pgsql') {
            return false;
        }

        $host = strtolower(trim((string) ($configuration['host'] ?? '')));
        $username = trim((string) ($configuration['username'] ?? ''));
        $schemas = $this->schemas($configuration);

        return $host === self::PRODUCTION_HOST
            || ($username === self::PRODUCTION_USERNAME && in_array('prod', $schemas, true));
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private function schemas(array $configuration): array
    {
        $configured = $configuration['search_path'] ?? $configuration['schema'] ?? '';
        $schemas = is_array($configured) ? $configured : explode(',', (string) $configured);

        return array_values(array_filter(array_map(
            static fn (mixed $schema): string => strtolower(trim((string) $schema, " \t\n\r\0\x0B\"'")),
            $schemas,
        )));
    }
}
