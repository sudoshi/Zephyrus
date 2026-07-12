<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Laravel's PostgreSQL `migrate:fresh` only discovers tables on the
     * configured search path (`prod,public`). Zephyrus deliberately owns many
     * additional schemas, so data in ops, hosp_org, integration, OCEL, and the
     * other application schemas otherwise survives between PHPUnit processes.
     *
     * RefreshDatabase still owns per-test transactions. This one guarded reset
     * establishes the empty non-search-path baseline immediately after its
     * first migration pass and before the first test executes.
     */
    private static bool $multiSchemaBaselineReset = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetNonSearchPathSchemasOnce();
        $this->withoutVite();
    }

    private function resetNonSearchPathSchemasOnce(): void
    {
        if (
            self::$multiSchemaBaselineReset
            || ! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)
        ) {
            return;
        }

        $connection = (string) config('database.default');
        $databaseName = (string) config("database.connections.{$connection}.database");
        if (! app()->environment('testing') || $databaseName !== 'zephyrus_test') {
            throw new RuntimeException(
                "Refusing the PHPUnit multi-schema reset outside the guarded zephyrus_test database; resolved {$databaseName}."
            );
        }

        // RefreshDatabase has migrated the prod/public search path and opened
        // the first test transaction. Temporarily leave that empty wrapper so
        // the reset becomes the durable process baseline, then restore it for
        // the test and for Laravel's registered teardown callback.
        $database = DB::connection();
        if ($database->transactionLevel() !== 1) {
            throw new RuntimeException('Expected one RefreshDatabase wrapper transaction before the multi-schema reset.');
        }
        $database->rollBack();

        $tables = collect(DB::select(<<<'SQL'
            SELECT format('%I.%I', schemaname, tablename) AS qualified_name
            FROM pg_tables
            WHERE schemaname NOT LIKE 'pg_%'
              AND schemaname <> 'information_schema'
              AND (
                    schemaname NOT IN ('prod', 'public', 'hosp_ref')
                    OR (
                        schemaname = 'hosp_ref'
                        AND tablename IN (
                            'ancillary_barrier_reasons',
                            'ancillary_milestone_types',
                            'lab_test_catalog',
                            'rad_modalities',
                            'rad_subspecialties',
                            'staff_qualifications',
                            'staff_role_qualification_requirements',
                            'staff_roles'
                        )
                    )
                  )
            ORDER BY schemaname, tablename
            SQL))
            ->pluck('qualified_name')
            ->filter()
            ->all();

        if ($tables !== []) {
            DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
        }

        $database->beginTransaction();
        self::$multiSchemaBaselineReset = true;
    }
}
