<?php

namespace Tests\Feature\Admin;

use App\Models\Auth\UserExternalIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class IdentityLifecycleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_identity_and_user_purge_columns_exist(): void
    {
        foreach ([
            'is_active', 'unlinked_at', 'unlinked_by_user_id', 'unlink_reason',
            'relinked_at', 'relinked_by_user_id', 'relink_reason',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('prod.user_external_identities', $column),
                "Missing governed external identity column {$column}",
            );
        }

        $this->assertTrue(Schema::hasColumn('prod.users', 'identity_purged_at'));
        $this->assertTrue(Schema::hasColumn('prod.users', 'identity_purge_request_uuid'));
        $this->assertTrue(Schema::hasTable('governance.identity_link_events'));
    }

    public function test_identity_events_are_append_only_and_purge_is_a_database_allowed_governed_action(): void
    {
        $trigger = DB::selectOne(<<<'SQL'
            SELECT t.tgenabled
            FROM pg_trigger t
            WHERE t.tgrelid = 'governance.identity_link_events'::regclass
              AND t.tgname = 'identity_link_events_append_only'
              AND NOT t.tgisinternal
        SQL);
        $this->assertNotNull($trigger);
        $this->assertSame('O', $trigger->tgenabled);

        $constraint = DB::selectOne(<<<'SQL'
            SELECT pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            WHERE c.conrelid = 'governance.change_requests'::regclass
              AND c.contype = 'c'
              AND pg_get_constraintdef(c.oid) LIKE '%purge_user_identity%'
        SQL);
        $this->assertNotNull($constraint);
        $this->assertStringContainsString('purge_user_identity', $constraint->definition);
    }

    public function test_identity_event_foreign_keys_preserve_subject_and_actor_accountability(): void
    {
        $foreignKeys = collect(DB::select(<<<'SQL'
            SELECT a.attname AS column_name, confdeltype
            FROM pg_constraint c
            JOIN unnest(c.conkey) WITH ORDINALITY AS keys(attnum, ordinality) ON true
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = keys.attnum
            WHERE c.conrelid = 'governance.identity_link_events'::regclass
              AND c.contype = 'f'
        SQL))->keyBy('column_name');

        foreach (['external_identity_id', 'subject_user_id', 'actor_user_id'] as $column) {
            $this->assertTrue($foreignKeys->has($column), "Missing identity event FK for {$column}");
            $this->assertSame('r', $foreignKeys[$column]->confdeltype, "{$column} must use ON DELETE RESTRICT");
        }
    }

    public function test_migration_backfills_a_hash_only_baseline_for_existing_links(): void
    {
        $user = User::factory()->create();
        $identity = UserExternalIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => 'authentik',
            'provider_subject' => 'historical-sensitive-subject',
            'provider_email_at_link' => 'historical@example.test',
            'linked_at' => now()->subYear(),
        ]);

        $migration = require database_path('migrations/2026_07_13_000100_govern_external_identity_lifecycle.php');
        $migration->up();

        $event = DB::table('governance.identity_link_events')
            ->where('external_identity_id', $identity->id)
            ->sole();

        $this->assertSame('linked', $event->event_type);
        $this->assertSame(hash('sha256', 'authentik:historical-sensitive-subject'), $event->provider_subject_sha256);
        $this->assertSame(hash('sha256', 'historical@example.test'), $event->provider_email_sha256);
        $this->assertNull($event->actor_user_id);
        $this->assertStringNotContainsString('historical-sensitive-subject', json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('historical@example.test', json_encode($event, JSON_THROW_ON_ERROR));

        $migration->up();
        $this->assertSame(
            1,
            DB::table('governance.identity_link_events')->where('external_identity_id', $identity->id)->count(),
        );
    }
}
