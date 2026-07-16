<?php

namespace Tests\Feature\Admin;

use App\Models\Audit\UserEvent;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BulkDeactivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_endpoints_require_the_manage_identity_capability(): void
    {
        $frontline = $this->user('user');
        $auditor = $this->user('auditor');

        $this->actingAs($frontline)
            ->postJson('/users/bulk-deactivation/preview', ['user_ids' => [1]])
            ->assertForbidden();
        $this->actingAs($auditor)
            ->postJson('/users/bulk-deactivation/preview', ['user_ids' => [1]])
            ->assertForbidden();
        $this->actingAs($auditor)
            ->postJson('/users/bulk-deactivation', ['user_ids' => [1], 'change_reason' => 'bulk_account_deactivation'])
            ->assertForbidden();
    }

    public function test_preview_reports_blocked_members_with_reasons_and_leaves_no_side_effects(): void
    {
        $admin = $this->user('admin');
        $ordinary = $this->user('user');
        $protected = $this->user('user', ['is_protected' => true]);
        $inactive = $this->user('user', ['is_active' => false]);
        $ordinary->createToken('keep-me');

        $response = $this->actingAs($admin)->postJson('/users/bulk-deactivation/preview', [
            'user_ids' => [$ordinary->id, $protected->id, $inactive->id, $admin->id, 999999],
        ])->assertOk()->json();

        $this->assertSame(1, $response['eligible_count']);
        $this->assertSame(4, $response['blocked_count']);
        $byId = collect($response['members'])->keyBy('id');
        $this->assertTrue($byId[$ordinary->id]['eligible']);
        $this->assertSame('protected_account', $byId[$protected->id]['blocked_reason']);
        $this->assertSame('already_inactive', $byId[$inactive->id]['blocked_reason']);
        $this->assertSame('self_deactivation', $byId[$admin->id]['blocked_reason']);
        $this->assertSame('user_not_found', $byId[999999]['blocked_reason']);

        // The simulation rolled back: nothing was deactivated or revoked.
        $this->assertTrue($ordinary->fresh()->is_active);
        $this->assertSame(1, $ordinary->tokens()->count());
        $this->assertSame(0, UserEvent::query()->where('action', 'administration.user.deactivated')->count());
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.bulk_deactivation_previewed',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_preview_reports_the_combined_last_administrator_effect(): void
    {
        $actor = $this->user('identity_admin');
        $firstAdmin = $this->user('admin');
        $secondAdmin = $this->user('admin');
        [$lowerId, $higherId] = $firstAdmin->id < $secondAdmin->id
            ? [$firstAdmin->id, $secondAdmin->id]
            : [$secondAdmin->id, $firstAdmin->id];

        $response = $this->actingAs($actor)->postJson('/users/bulk-deactivation/preview', [
            'user_ids' => [$firstAdmin->id, $secondAdmin->id],
        ])->assertOk()->json();

        $byId = collect($response['members'])->keyBy('id');
        $this->assertTrue($byId[$lowerId]['eligible']);
        $this->assertSame('last_administrator', $byId[$higherId]['blocked_reason']);
        $this->assertTrue($firstAdmin->fresh()->is_active);
        $this->assertTrue($secondAdmin->fresh()->is_active);
    }

    public function test_execution_requires_recent_step_up_authentication(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');

        $this->actingAs($admin)->postJson('/users/bulk-deactivation', [
            'user_ids' => [$target->id],
            'change_reason' => 'bulk_account_deactivation',
        ])->assertStatus(428);

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_execution_is_all_or_nothing_when_any_member_is_blocked(): void
    {
        $admin = $this->user('admin');
        $ordinary = $this->user('user');
        $protected = $this->user('user', ['is_protected' => true]);

        $this->actingAs($admin)->withSession($this->stepUp())->postJson('/users/bulk-deactivation', [
            'user_ids' => [$ordinary->id, $protected->id],
            'change_reason' => 'workforce_offboarding',
        ])->assertStatus(422);

        $this->assertTrue($ordinary->fresh()->is_active);
        $this->assertTrue($protected->fresh()->is_active);
        $this->assertSame(0, UserEvent::query()->where('action', 'administration.user.deactivated')->count());
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.bulk_deactivation_denied',
            'outcome' => 'denied',
            'reason' => 'protected_account',
        ]);
    }

    public function test_execution_deactivates_revokes_and_audits_every_member_transactionally(): void
    {
        $admin = $this->user('admin');
        $first = $this->user('user');
        $second = $this->user('user');
        $first->createToken('mobile');
        DB::table('prod.sessions')->insert([
            'id' => 'bulk-session-1',
            'user_id' => $second->id,
            'ip_address' => '10.0.0.4',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)->withSession($this->stepUp())->postJson('/users/bulk-deactivation', [
            'user_ids' => [$first->id, $second->id],
            'change_reason' => 'access_review_remediation',
        ])->assertOk()->assertJson(['deactivated_count' => 2]);

        foreach ([$first, $second] as $member) {
            $member = $member->fresh();
            $this->assertFalse($member->is_active);
            $this->assertNotNull($member->deactivated_at);
            $this->assertSame(0, $member->tokens()->count());
            $this->assertDatabaseHas('audit.user_events', [
                'action' => 'administration.user.deactivated',
                'reason' => 'access_review_remediation',
                'target_id' => (string) $member->id,
            ]);
            $this->assertDatabaseHas('audit.user_events', [
                'action' => 'security.user.access_revoked',
                'target_id' => (string) $member->id,
            ]);
        }
        $this->assertSame(0, DB::table('prod.sessions')->where('user_id', $second->id)->count());
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.bulk_deactivation_executed',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_targeted_access_revocation_revokes_credentials_but_never_protected_accounts(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('user');
        $target->createToken('mobile');

        $this->actingAs($admin)->post('/users/'.$target->id.'/revoke-access', [
            'change_reason' => 'suspected_compromise',
        ])->assertRedirect();
        $fresh = $target->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(1, (int) $fresh->auth_session_version);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'security.user.access_revoked',
            'reason' => 'suspected_compromise',
            'target_id' => (string) $target->id,
        ]);

        $protected = $this->user('user', ['is_protected' => true]);
        $this->actingAs($admin)->from('/users/'.$protected->id.'/edit')
            ->post('/users/'.$protected->id.'/revoke-access', [
                'change_reason' => 'suspected_compromise',
            ])->assertSessionHasErrors('user');
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'administration.user.revoke_access_denied',
            'reason' => 'protected_account',
        ]);

        $frontline = $this->user('user');
        $this->actingAs($frontline)->post('/users/'.$target->id.'/revoke-access', [
            'change_reason' => 'suspected_compromise',
        ])->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'is_active' => true,
            'must_change_password' => false,
        ], $overrides));
    }
}
