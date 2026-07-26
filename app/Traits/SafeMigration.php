<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait SafeMigration
{
    protected function isLocalEnvironment(): bool
    {
        return app()->environment('local');
    }

    protected function safeDropIfExists(string $table): void
    {
        if ($this->isLocalEnvironment()) {
            Schema::dropIfExists($table);
        }
    }

    protected function safeDropSchema(string $schema): void
    {
        if ($this->isLocalEnvironment()) {
            DB::unprepared("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        }
    }

    protected function constraintExists(string $table, string $constraintName): bool
    {
        $result = DB::select("
            SELECT 1
            FROM information_schema.table_constraints
            WHERE table_schema = split_part(?, '.', 1)
            AND table_name = split_part(?, '.', 2)
            AND constraint_name = ?
        ", [$table, $table, $constraintName]);

        return ! empty($result);
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select("
            SELECT 1
            FROM pg_indexes
            WHERE schemaname = split_part(?, '.', 1)
            AND tablename = split_part(?, '.', 2)
            AND indexname = ?
        ", [$table, $table, $indexName]);

        return ! empty($result);
    }
}
