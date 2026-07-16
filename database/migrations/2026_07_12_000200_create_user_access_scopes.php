<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prod.user_access_scopes')) {
            Schema::create('prod.user_access_scopes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('organization_id')->nullable();
                $table->unsignedBigInteger('facility_id')->nullable();
                $table->unsignedBigInteger('granted_by_user_id')->nullable();
                $table->string('grant_reason', 500);
                $table->timestampTz('valid_from')->useCurrent();
                $table->timestampTz('valid_until')->nullable();
                $table->timestampTz('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by_user_id')->nullable();
                $table->string('revocation_reason', 500)->nullable();
                $table->timestampsTz();

                $table->foreign('user_id')->references('id')->on('prod.users')->restrictOnDelete();
                $table->foreign('organization_id')->references('organization_id')->on('hosp_org.organizations')->restrictOnDelete();
                $table->foreign('facility_id')->references('facility_id')->on('hosp_org.facilities')->restrictOnDelete();
                $table->foreign('granted_by_user_id')->references('id')->on('prod.users')->restrictOnDelete();
                $table->foreign('revoked_by_user_id')->references('id')->on('prod.users')->restrictOnDelete();

                $table->index(['user_id', 'revoked_at'], 'user_access_scopes_effective_idx');
                $table->index('organization_id', 'user_access_scopes_organization_idx');
                $table->index('facility_id', 'user_access_scopes_facility_idx');
            });
        }

        DB::statement(<<<'SQL'
            ALTER TABLE prod.user_access_scopes
            DROP CONSTRAINT IF EXISTS user_access_scopes_one_boundary_chk
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE prod.user_access_scopes
            ADD CONSTRAINT user_access_scopes_one_boundary_chk
            CHECK (num_nonnulls(organization_id, facility_id) = 1)
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS user_access_scopes_active_org_unique
            ON prod.user_access_scopes (user_id, organization_id)
            WHERE organization_id IS NOT NULL AND revoked_at IS NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS user_access_scopes_active_facility_unique
            ON prod.user_access_scopes (user_id, facility_id)
            WHERE facility_id IS NOT NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('prod.user_access_scopes');
    }
};
