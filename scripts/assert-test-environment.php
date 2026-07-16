<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

Tests\Support\TestEnvironmentGuard::enforce(dirname(__DIR__));

fwrite(STDOUT, "Test environment guard passed.\n");
