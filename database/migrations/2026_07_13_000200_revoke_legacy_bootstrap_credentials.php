<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, string> */
    private const COMPROMISED_BOOTSTRAP_IDENTITIES = [
        'admin' => 'admin@example.com',
        'sanjay' => 'sanjay@example.com',
        'kartheek' => 'kartheek@example.com',
        'darshan' => 'darshan@example.com',
        'devsheth' => 'devsheth@example.com',
        'acumenus' => 'acumenus@example.com',
        'hakan' => 'hakan@example.com',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('prod.users')) {
            return;
        }

        DB::transaction(function (): void {
            $users = DB::table('prod.users')
                ->where(function ($query): void {
                    foreach (self::COMPROMISED_BOOTSTRAP_IDENTITIES as $username => $email) {
                        $query->orWhere(function ($pair) use ($username, $email): void {
                            $pair->where('username', $username)->whereRaw('lower(email) = ?', [$email]);
                        });
                    }
                })
                ->lockForUpdate()
                ->get(['id']);

            foreach ($users as $user) {
                DB::table('prod.personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')
                    ->where('tokenable_id', $user->id)->delete();
                if (Schema::hasTable('prod.sessions')) {
                    DB::table('prod.sessions')->where('user_id', $user->id)->delete();
                }
                if (Schema::hasTable('prod.model_has_roles')) {
                    DB::table('prod.model_has_roles')->where('model_type', 'App\\Models\\User')
                        ->where('model_id', $user->id)->delete();
                }
                if (Schema::hasTable('prod.model_has_permissions')) {
                    DB::table('prod.model_has_permissions')->where('model_type', 'App\\Models\\User')
                        ->where('model_id', $user->id)->delete();
                }
                if (Schema::hasTable('prod.user_external_identities')) {
                    $identities = DB::table('prod.user_external_identities')
                        ->where('user_id', $user->id)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->get();

                    foreach ($identities as $identity) {
                        DB::table('governance.identity_link_events')->insert([
                            'identity_link_event_uuid' => (string) Str::uuid7(),
                            'external_identity_id' => $identity->id,
                            'subject_user_id' => $user->id,
                            'event_type' => 'unlinked',
                            'provider' => $identity->provider,
                            'provider_subject_sha256' => hash(
                                'sha256',
                                $identity->provider.':'.$identity->provider_subject,
                            ),
                            'provider_email_sha256' => filled($identity->provider_email_at_link)
                                ? hash('sha256', strtolower((string) $identity->provider_email_at_link))
                                : null,
                            'actor_user_id' => null,
                            'reason' => 'Known bootstrap credential revoked by production safety migration.',
                            'occurred_at' => now(),
                            'metadata' => json_encode(
                                ['source' => 'legacy_bootstrap_revocation'],
                                JSON_THROW_ON_ERROR,
                            ),
                        ]);

                        DB::table('prod.user_external_identities')->where('id', $identity->id)->update([
                            'is_active' => false,
                            'unlinked_at' => now(),
                            'unlink_reason' => 'Known bootstrap credential revoked by production safety migration.',
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('prod.users')->where('id', $user->id)->update([
                    'password' => Hash::make(Str::random(96)),
                    'role' => 'user',
                    'is_active' => false,
                    'must_change_password' => true,
                    'remember_token' => null,
                    'auth_session_version' => DB::raw('auth_session_version + 1'),
                    'deactivated_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: known/shared credentials and their access
        // grants must never be restored by a migration rollback.
    }
};
