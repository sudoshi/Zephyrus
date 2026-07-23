<?php

namespace Tests\Unit\Database;

use App\Database\ProductionDatabaseReadOnlyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductionDatabaseReadOnlyGuardTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    #[DataProvider('connectionPolicies')]
    public function test_it_identifies_connections_that_must_be_read_only(
        string $environment,
        array $configuration,
        bool $expected,
    ): void {
        $guard = new ProductionDatabaseReadOnlyGuard;

        $this->assertSame($expected, $guard->shouldProtect($configuration, $environment));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function connectionPolicies(): iterable
    {
        $productionConnection = [
            'driver' => 'pgsql',
            'host' => 'pgsql.acumenus.net',
            'username' => 'smudoshi',
            'search_path' => 'prod,public',
        ];

        yield 'local app using canonical production host' => [
            'local',
            $productionConnection,
            true,
        ];

        yield 'staging app using production identity and schema' => [
            'staging',
            [
                'driver' => 'pgsql',
                'host' => '50.32.48.115',
                'username' => 'smudoshi',
                'schema' => 'prod',
            ],
            true,
        ];

        yield 'production app retains its normal write authority' => [
            'production',
            $productionConnection,
            false,
        ];

        yield 'isolated local test database is not affected' => [
            'testing',
            [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'username' => 'zephyrus_test',
                'search_path' => ['prod', 'public'],
            ],
            false,
        ];

        yield 'non PostgreSQL connection is not affected' => [
            'local',
            [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
            false,
        ];
    }
}
