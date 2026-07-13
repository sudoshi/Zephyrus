<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADM-IAM: additive provisioning-state authority for accounts.
 *
 * `invited` - created through the temp-password invitation flow;
 * `jit`     - created just-in-time by a validated IdP login;
 * `scim`    - created/managed by a SCIM provisioning client (reserved);
 * `synced`  - pre-existing local account now bound to an external identity;
 * `local`   - locally administered account with no external identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prod.users', function (Blueprint $table): void {
            if (! Schema::hasColumn('prod.users', 'provisioning_state')) {
                $table->string('provisioning_state', 20)->default('local');
            }
        });

        DB::statement(<<<'SQL'
            ALTER TABLE prod.users
            DROP CONSTRAINT IF EXISTS users_provisioning_state_chk
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE prod.users
            ADD CONSTRAINT users_provisioning_state_chk
            CHECK (provisioning_state IN ('invited', 'jit', 'scim', 'synced', 'local'))
        SQL);

        // Backfill: an account with any recorded external identity is bound to
        // the IdP ('synced'); everything else remains locally administered.
        // New JIT creations record 'jit' at creation time going forward.
        DB::statement(<<<'SQL'
            UPDATE prod.users
            SET provisioning_state = 'synced'
            WHERE provisioning_state = 'local'
              AND EXISTS (
                  SELECT 1 FROM prod.user_external_identities i
                  WHERE i.user_id = prod.users.id
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE prod.users
            DROP CONSTRAINT IF EXISTS users_provisioning_state_chk
        SQL);
        Schema::table('prod.users', function (Blueprint $table): void {
            if (Schema::hasColumn('prod.users', 'provisioning_state')) {
                $table->dropColumn('provisioning_state');
            }
        });
    }
};
