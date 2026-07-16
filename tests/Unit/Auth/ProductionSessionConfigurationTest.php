<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\ProductionSessionConfiguration;
use PHPUnit\Framework\TestCase;

class ProductionSessionConfigurationTest extends TestCase
{
    public function test_secure_host_scoped_database_session_is_accepted(): void
    {
        $violations = (new ProductionSessionConfiguration)->violations([
            'driver' => 'database',
            'encrypt' => true,
            'secure' => true,
            'http_only' => true,
            'same_site' => 'lax',
            'path' => '/',
            'domain' => 'zephyrus.acumenus.net',
        ], 'https://zephyrus.acumenus.net');

        $this->assertSame([], $violations);
    }

    public function test_insecure_or_cross_subdomain_session_configuration_is_rejected(): void
    {
        $violations = (new ProductionSessionConfiguration)->violations([
            'driver' => 'file',
            'encrypt' => false,
            'secure' => false,
            'http_only' => false,
            'same_site' => 'none',
            'path' => '/admin',
            'domain' => '.acumenus.net',
        ], 'https://zephyrus.acumenus.net');

        $this->assertCount(7, $violations);
    }
}
