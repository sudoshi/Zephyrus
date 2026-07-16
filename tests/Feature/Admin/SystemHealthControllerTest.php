<?php

namespace Tests\Feature\Admin;

use App\Models\Governance\SystemHealthObservation;
use App\Models\User;
use App\Services\Admin\SystemHealthService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class SystemHealthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_routes_are_capability_gated_and_auditors_are_read_only(): void
    {
        $plain = User::factory()->create(['role' => 'user', 'is_active' => true, 'must_change_password' => false]);
        $facilityAdmin = User::factory()->create(['role' => 'facility_admin', 'is_active' => true, 'must_change_password' => false]);
        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($plain)->get('/admin/system-health')->assertForbidden();
        $this->actingAs($facilityAdmin)->get('/admin/system-health')->assertForbidden();

        $this->actingAs($auditor)->get('/admin/system-health')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SystemHealth')
                ->where('canRunDiagnostics', false)
                ->where('snapshot.overallStatus', 'degraded')
                ->has('snapshot.observations', count(config('admin-health.components'))));

        $this->actingAs($auditor)->postJson('/admin/system-health/diagnostics')->assertForbidden();
        $this->assertDatabaseCount('governance.system_health_observations', 0);
    }

    public function test_admin_can_record_a_bounded_manual_batch_and_audited_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');
        config()->set('admin-health.backup.evidence_path', '/private/backup/evidence-that-does-not-exist');
        config()->set('admin-health.tls.certificate_path', '/private/tls/certificate-that-does-not-exist');

        $response = $this->actingAs($admin)
            ->postJson('/admin/system-health/diagnostics')
            ->assertOk()
            ->assertJsonPath('batchObservationCount', count(config('admin-health.components')) - 1)
            ->assertJsonPath('contract.appendOnly', true)
            ->assertJsonPath('contract.externalCallsAllowed', false)
            ->assertJsonPath('observations.0.key', 'database');

        $batchUuid = $response->json('batchUuid');
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('correlationId'));
        $this->assertSame($batchUuid, $response->json('correlationId'));
        $this->assertDatabaseCount('governance.system_health_observations', count(config('admin-health.components')) - 1);
        $this->assertDatabaseHas('governance.system_health_observations', [
            'batch_uuid' => $batchUuid,
            'origin' => 'manual',
            'recorded_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseMissing('governance.system_health_observations', [
            'batch_uuid' => $batchUuid,
            'component_key' => 'scheduler',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'administration.system_health.diagnostic',
            'outcome' => 'success',
            'target_type' => 'system_health',
            'target_id' => $batchUuid,
        ]);
        $this->assertStringNotContainsString('/private/backup', $response->getContent());
        $this->assertStringNotContainsString('/private/tls', $response->getContent());
    }

    public function test_scheduled_command_records_scheduler_evidence_and_component_detail_is_bounded(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');

        $this->assertSame(0, Artisan::call('admin:observe-system-health'));

        $this->assertDatabaseCount('governance.system_health_observations', count(config('admin-health.components')));
        $this->assertDatabaseHas('governance.system_health_observations', [
            'component_key' => 'scheduler',
            'status' => 'healthy',
            'origin' => 'scheduled',
            'recorded_by_user_id' => null,
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($admin)->get('/admin/system-health/scheduler')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SystemHealth')
                ->where('snapshot.selectedComponent.key', 'scheduler')
                ->where('snapshot.selectedComponent.status', 'healthy')
                ->where('snapshot.selectedComponent.details.scheduledInvocation', true));

        $this->actingAs($admin)->get('/admin/system-health/not_a_component')->assertNotFound();
    }

    public function test_expired_green_observation_becomes_unknown_without_rewriting_evidence(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');
        config()->set('admin-health.fresh_for_seconds', 60);
        $service = app(SystemHealthService::class);
        $snapshot = $service->collect('scheduled');
        $database = collect($snapshot['observations'])->firstWhere('key', 'database');
        $this->assertSame('healthy', $database['status']);

        $this->travel(61)->seconds();
        $expired = collect($service->snapshot()['observations'])->firstWhere('key', 'database');

        $this->assertSame('unknown', $expired['status']);
        $this->assertSame('healthy', $expired['recordedStatus']);
        $this->assertTrue($expired['stale']);
        $this->assertDatabaseHas('governance.system_health_observations', [
            'component_key' => 'database',
            'status' => 'healthy',
        ]);
    }

    public function test_critical_required_component_routes_one_operational_alert_on_transition(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');
        // Force the required "sessions" component critical via an invalid cookie policy.
        config()->set('session.http_only', false);

        $service = app(SystemHealthService::class);
        $service->collect('scheduled');

        $sessions = collect($service->snapshot()['observations'])->firstWhere('key', 'sessions');
        $this->assertSame('critical', $sessions['status']);
        // The shared on-call delivery ledger recorded the transition (inert by
        // default => 'inert' outcome, but the row exists and is PHI-free).
        $this->assertDatabaseHas('integration.operational_alert_deliveries', [
            'alert_domain' => 'system_health',
            'alert_code' => 'system_health_component_critical',
            'severity' => 'crit',
            'subject_type' => 'system_health_component',
            'subject_reference' => 'sessions',
        ]);
        $deliveredAfterFirst = DB::table('integration.operational_alert_deliveries')
            ->where('subject_reference', 'sessions')->count();

        // A second collection with the SAME critical state must NOT re-page.
        $service->collect('scheduled');
        $this->assertSame(
            $deliveredAfterFirst,
            DB::table('integration.operational_alert_deliveries')->where('subject_reference', 'sessions')->count(),
            'A persistently critical component must not re-page on the next tick.',
        );
    }

    public function test_operator_can_acknowledge_a_component_and_the_ledger_is_append_only(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');
        app(SystemHealthService::class)->collect('scheduled');

        $auditor = User::factory()->create(['role' => 'auditor', 'is_active' => true, 'must_change_password' => false]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        // viewSystemHealth-only actors cannot acknowledge.
        $this->actingAs($auditor)
            ->postJson('/admin/system-health/database/acknowledge', ['reason' => 'Acknowledged during triage.'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/admin/system-health/database/acknowledge', ['reason' => 'Owned by platform on-call for triage.'])
            ->assertCreated()
            ->assertJsonPath('componentKey', 'database');

        $this->assertDatabaseHas('governance.system_health_acknowledgements', [
            'component_key' => 'database',
            'acknowledged_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'actor_user_id' => $admin->id,
            'action' => 'administration.system_health.acknowledge',
            'target_type' => 'system_health_component',
            'target_id' => 'database',
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/system-health/database/acknowledge', ['reason' => 'short'])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->postJson('/admin/system-health/not_a_component/acknowledge', ['reason' => 'Reason long enough here.'])
            ->assertNotFound();

        $row = DB::table('governance.system_health_acknowledgements')->where('component_key', 'database')->first();
        DB::beginTransaction();
        try {
            DB::table('governance.system_health_acknowledgements')
                ->where('system_health_acknowledgement_id', $row->system_health_acknowledgement_id)
                ->update(['reason' => 'tampered reason value']);
            $this->fail('System health acknowledgements must be append-only.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_health_observation_ledger_rejects_update_and_delete(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');
        app(SystemHealthService::class)->collect('scheduled');
        $row = SystemHealthObservation::query()->firstOrFail();

        foreach ([
            fn () => SystemHealthObservation::query()->whereKey($row->getKey())->update(['summary' => 'tampered']),
            fn () => SystemHealthObservation::query()->whereKey($row->getKey())->delete(),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                $this->fail('System health evidence must be append-only.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
        }
    }
}
