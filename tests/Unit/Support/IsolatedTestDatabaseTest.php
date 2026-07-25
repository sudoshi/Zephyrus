<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedTestDatabase;

class IsolatedTestDatabaseTest extends TestCase
{
    public function test_cleanup_never_attempts_to_terminate_another_database_role(): void
    {
        $query = IsolatedTestDatabase::TERMINATE_OWNED_CONNECTIONS_QUERY;

        $this->assertStringContainsString('datname = :database', $query);
        $this->assertStringContainsString('pid <> pg_backend_pid()', $query);
        $this->assertStringContainsString('usename = current_user', $query);
    }
}
