<?php

namespace Tests\Feature\Integrations;

use App\Contracts\OperationalAlertChannel;
use App\Integrations\Healthcare\Services\CredentialRotationAlertService;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use App\Services\Alerting\OperationalAlert;
use App\Services\Alerting\OperationalAlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INT-SECRET — credential-rotation threshold crossings page through the shared
 * operational-alert lifecycle (dispatcher + delivery ledger), fire once per
 * band, and are PHI-free/secret-free.
 */
final class CredentialRotationAlertTest extends TestCase
{
    use RefreshDatabase;

    private int $sourceId;

    private int $credentialId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse((string) DB::scalar('select now()')));
        $this->artisan('deployment:seed-registry')->assertSuccessful();
        $organization = Organization::create([
            'organization_key' => 'ROTATION_ALERT_IDN',
            'name' => 'Rotation Alert IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'ROTATION_ALERT_FACILITY',
            'facility_name' => 'Rotation Alert Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->sourceId = (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid7(),
            'source_key' => 'rotation.alert.test',
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => 'ROTATION_ALERT_IDN',
            'facility_key' => 'ROTATION_ALERT_FACILITY',
            'source_name' => 'Rotation Alert Test',
            'vendor' => 'Test Vendor',
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'lifecycle_state' => 'validating',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
        app(\App\Integrations\Healthcare\Services\SourceConfigurationVersionService::class)->initialize(
            $this->sourceId,
            null,
            'Initialize rotation alert test configuration authority.',
            (string) Str::uuid7(),
        );
        app(\App\Integrations\Healthcare\Services\SourceLifecycleService::class)->initialize(
            $this->sourceId,
            null,
            'Initialize rotation alert test lifecycle authority.',
        );
        $this->credentialId = (int) DB::table('integration.source_credentials')->insertGetId([
            'source_id' => $this->sourceId,
            'credential_key' => 'rotating-oauth-client',
            'credential_type' => 'oauth2_client',
            'secret_ref' => 'vault://clinical/rotation/client',
            'is_active' => true,
            'credential_state' => 'active',
            'valid_from' => now()->subDay(),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_credential_id');
        $versionId = (int) DB::table('integration.source_credential_versions')->insertGetId([
            'credential_version_uuid' => (string) Str::uuid7(),
            'source_credential_id' => $this->credentialId,
            'source_id' => $this->sourceId,
            'version_number' => 1,
            'credential_type' => 'oauth2_client',
            'secret_ref' => 'vault://clinical/rotation/client',
            'credential_state' => 'active',
            'valid_from' => now()->subDay(),
            'rotates_at' => now()->addDays(25), // Inside the 30-day band.
            'authority_sha256' => hash('sha256', 'rotation-alert-authority'),
            'change_reason' => 'Seed a rotating credential authority for alerting tests.',
            'created_at' => now(),
        ], 'source_credential_version_id');
        DB::table('integration.source_credentials')
            ->where('source_credential_id', $this->credentialId)
            ->update(['current_credential_version_id' => $versionId, 'rotates_at' => now()->addDays(25)]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_inert_by_default_records_the_no_channel_delivery_and_dedupes_the_band(): void
    {
        // Default dispatcher has inert-by-default channels; a threshold crossing
        // still records an attempt so the absence of paging is auditable.
        $result = app(CredentialRotationAlertService::class)->sweep();
        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(1, $result['inert']);

        $this->assertDatabaseHas('integration.credential_rotation_alert_states', [
            'source_credential_id' => $this->credentialId,
            'rotation_band' => 'due_30',
            'dispatch_outcome' => 'inert',
        ]);
        // An inert crossing is retried on the next sweep (nothing was delivered).
        $second = app(CredentialRotationAlertService::class)->sweep();
        $this->assertSame(1, $second['inert']);
    }

    public function test_delivered_threshold_crossing_pages_once_per_band(): void
    {
        $this->bindDeliveringChannel();

        $first = app(CredentialRotationAlertService::class)->sweep();
        $this->assertSame(1, $first['dispatched']);

        // The delivery lands in the SAME shared PHI-free ledger the breach path
        // uses, tagged with the credential_rotation subject type.
        $this->assertSame(1, DB::table('integration.operational_alert_deliveries')
            ->where('alert_domain', 'integration')
            ->where('alert_code', 'credential_rotation_due')
            ->where('subject_type', 'credential_rotation')
            ->where('outcome', 'delivered')
            ->count());

        // A second sweep in the same band does NOT page again (flap damping).
        $second = app(CredentialRotationAlertService::class)->sweep();
        $this->assertSame(0, $second['dispatched']);
        $this->assertSame(1, $second['deduped']);
        $this->assertSame(1, DB::table('integration.credential_rotation_alert_states')
            ->where('source_credential_id', $this->credentialId)
            ->where('dispatch_outcome', 'dispatched')
            ->count());
    }

    public function test_a_new_more_urgent_band_pages_again(): void
    {
        $this->bindDeliveringChannel();
        app(CredentialRotationAlertService::class)->sweep();

        // A rotation produces a NEW immutable version (the ledger is append-only);
        // point the credential projection at it with a nearer 5-day deadline so a
        // more urgent band crosses and pages again.
        $previousVersionId = (int) DB::table('integration.source_credentials')
            ->where('source_credential_id', $this->credentialId)
            ->value('current_credential_version_id');
        $newVersionId = (int) DB::table('integration.source_credential_versions')->insertGetId([
            'credential_version_uuid' => (string) Str::uuid7(),
            'source_credential_id' => $this->credentialId,
            'source_id' => $this->sourceId,
            'version_number' => 2,
            'previous_version_id' => $previousVersionId,
            'credential_type' => 'oauth2_client',
            'secret_ref' => 'vault://clinical/rotation/client',
            'credential_state' => 'active',
            'valid_from' => now()->subDay(),
            'rotates_at' => now()->addDays(5),
            'authority_sha256' => hash('sha256', 'rotation-alert-authority-v2'),
            'change_reason' => 'Rotate the credential nearer to its deadline for band-crossing tests.',
            'created_at' => now(),
        ], 'source_credential_version_id');
        DB::table('integration.source_credentials')
            ->where('source_credential_id', $this->credentialId)
            ->update(['current_credential_version_id' => $newVersionId, 'rotates_at' => now()->addDays(5)]);

        $result = app(CredentialRotationAlertService::class)->sweep();
        $this->assertSame(1, $result['dispatched']);
        $this->assertDatabaseHas('integration.credential_rotation_alert_states', [
            'source_credential_id' => $this->credentialId,
            'rotation_band' => 'due_7',
            'dispatch_outcome' => 'dispatched',
        ]);
    }

    public function test_alert_payload_carries_no_secret_reference(): void
    {
        $this->bindDeliveringChannel();
        app(CredentialRotationAlertService::class)->sweep();

        $ledger = DB::table('integration.operational_alert_deliveries')->get()->toJson();
        $this->assertStringNotContainsString('vault://clinical/rotation/client', $ledger);
        $this->assertStringNotContainsString('rotation/client', $ledger);
        $stateLedger = DB::table('integration.credential_rotation_alert_states')->get()->toJson();
        $this->assertStringNotContainsString('vault://', $stateLedger);
    }

    public function test_the_scheduled_command_runs_and_dedupes(): void
    {
        $this->bindDeliveringChannel();
        $this->artisan('integrations:dispatch-credential-rotation-alerts --limit=50')->assertSuccessful();
        $this->artisan('integrations:dispatch-credential-rotation-alerts --limit=50')->assertSuccessful();

        $this->assertSame(1, DB::table('integration.credential_rotation_alert_states')
            ->where('source_credential_id', $this->credentialId)
            ->where('dispatch_outcome', 'dispatched')
            ->count());
    }

    public function test_rotation_alert_state_ledger_is_append_only(): void
    {
        app(CredentialRotationAlertService::class)->sweep();
        $stateId = (int) DB::table('integration.credential_rotation_alert_states')
            ->where('source_credential_id', $this->credentialId)
            ->value('credential_rotation_alert_state_id');
        try {
            DB::transaction(fn () => DB::table('integration.credential_rotation_alert_states')
                ->where('credential_rotation_alert_state_id', $stateId)
                ->update(['dispatch_outcome' => 'dispatched']));
            $this->fail('The rotation alert state ledger accepted an update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    private function bindDeliveringChannel(): void
    {
        $this->app->instance(OperationalAlertDispatcher::class, new OperationalAlertDispatcher(
            [
                new class implements OperationalAlertChannel
                {
                    public function deliver(OperationalAlert $alert): int
                    {
                        return 1;
                    }

                    public function name(): string
                    {
                        return 'test_pager';
                    }
                },
            ],
            app(ClinicalContentGuard::class),
        ));
    }
}
