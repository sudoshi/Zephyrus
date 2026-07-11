<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION integration.reject_configuration_audit_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'integration configuration audits are append-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS integration_configuration_audits_append_only
                ON integration.configuration_audits;
            CREATE TRIGGER integration_configuration_audits_append_only
            BEFORE UPDATE OR DELETE ON integration.configuration_audits
            FOR EACH ROW EXECUTE FUNCTION integration.reject_configuration_audit_mutation();
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS integration_configuration_audits_append_only ON integration.configuration_audits');
        DB::statement('DROP FUNCTION IF EXISTS integration.reject_configuration_audit_mutation()');
    }
};
