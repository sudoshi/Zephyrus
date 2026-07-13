<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Tests\Support\IsolatedTestDatabase;
use Tests\Support\TestEnvironmentGuard;

TestEnvironmentGuard::enforce(dirname(__DIR__));

if (filter_var(getenv('TEST_DB_ISOLATION') ?: 'true', FILTER_VALIDATE_BOOL)) {
    IsolatedTestDatabase::provision();
}
