<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permit an approved resource profile to use either FHIR R4 type search or
 * type history. Existing profiles remain on search unless explicitly revised.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE integration.fhir_resource_profiles DROP CONSTRAINT fhir_resource_profiles_interaction_chk');
        DB::statement(<<<'SQL'
            ALTER TABLE integration.fhir_resource_profiles
                ADD CONSTRAINT fhir_resource_profiles_interaction_chk
                CHECK (polling_interaction IN ('search', 'history'))
        SQL);
    }

    public function down(): void
    {
        DB::table('integration.fhir_resource_profiles')
            ->where('polling_interaction', 'history')
            ->update([
                'polling_interaction' => 'search',
                'profile_status' => 'suspended',
                'poll_enabled' => false,
                'version_number' => DB::raw('version_number + 1'),
                'reason_code' => 'history_polling_rolled_back',
                'change_reason' => 'Return this resource profile to search polling before schema rollback.',
                'configured_by_user_id' => null,
                'correlation_uuid' => null,
                'updated_at' => now(),
            ]);
        DB::statement('ALTER TABLE integration.fhir_resource_profiles DROP CONSTRAINT fhir_resource_profiles_interaction_chk');
        DB::statement(<<<'SQL'
            ALTER TABLE integration.fhir_resource_profiles
                ADD CONSTRAINT fhir_resource_profiles_interaction_chk
                CHECK (polling_interaction IN ('search'))
        SQL);
    }
};
