<?php

declare(strict_types=1);
use Tests\Support\TestEnvironmentGuard;

require dirname(__DIR__).'/vendor/autoload.php';

TestEnvironmentGuard::enforce(dirname(__DIR__));

fwrite(STDOUT, "Test environment guard passed.\n");
