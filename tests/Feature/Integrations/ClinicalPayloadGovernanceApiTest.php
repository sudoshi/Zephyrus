<?php

namespace Tests\Feature\Integrations;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalPayloadException;
use App\Security\ClinicalPayloads\ClinicalPayloadQuarantineService;
use App\Security\ClinicalPayloads\ClinicalPayloadStore;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClinicalPayloadGovernanceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $facilityId;

    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Organization::query()->create([
            'organization_key' => 'PAYLOAD_GOVERNANCE_ORG',
            'name' => 'Payload Governance Organization',
            'kind' => 'idn',
        ]);
        $facility = Facility::query()->create([
            'facility_key' => 'PAYLOAD_GOVERNANCE_FACILITY',
            'organization_id' => $organization->organization_id,
            'facility_name' => 'Payload Governance Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        $this->sourceId = (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'payload.governance.source',
            'source_name' => 'Payload Governance Source',
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'system_class' => 'ehr',
            'environment' => 'testing',
            'interface_type' => 'hl7v2',
            'active_status' => 'testing',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
    }

    public function test_quarantine_release_requires_step_up_exact_payload_and_independent_approval(): void
    {
        $stored = app(ClinicalPayloadStore::class)->storeJson($this->sourceId, 'raw_message', [
            'patient' => 'RECOGNIZABLE-GOVERNED-QUARANTINE-PATIENT',
        ]);
        $quarantineId = app(ClinicalPayloadQuarantineService::class)->quarantine(
            $stored->payloadObjectId,
            $this->sourceId,
            'policy',
            'policy_review_required',
            'governance-test',
        );
        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $requestUrl = "/api/admin/integrations/sources/{$this->sourceId}/payload-quarantines/{$quarantineId}/release-requests";

        $this->selectScope($author)->postJson($requestUrl, [
            'reason' => 'Release after policy evidence and source ownership were independently reviewed.',
        ])->assertStatus(428)->assertJsonPath('error.code', 'step_up_required');

        $changeUuid = $this->selectScope($author)->withSession($this->stepUp())->postJson($requestUrl, [
            'reason' => 'Release after policy evidence and source ownership were independently reviewed.',
        ])->assertCreated()
            ->assertJsonPath('data.action', 'release_quarantined_payload')
            ->assertJsonPath('data.status', 'pending_approval')
            ->json('data.changeRequestUuid');

        $decisionUrl = "/api/admin/integrations/governed-changes/{$changeUuid}/decision";
        $this->selectScope($author)->withSession($this->stepUp())->postJson($decisionUrl, [
            'decision' => 'approved',
            'reason' => 'The author must not approve their own quarantine release.',
        ])->assertConflict()->assertJsonPath('error.code', 'author_approver_conflict');

        $this->selectScope($approver)->withSession($this->stepUp())->postJson($decisionUrl, [
            'decision' => 'approved',
            'reason' => 'Independent policy and integrity evidence supports bounded release.',
        ])->assertOk()->assertJsonPath('data.decision', 'approved');

        $executeUrl = "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/payload-quarantines/{$quarantineId}/execute-release";
        $this->selectScope($author)->withSession($this->stepUp())->postJson($executeUrl)
            ->assertOk()
            ->assertJsonPath('data.status', 'released');

        $this->assertDatabaseHas('raw.payload_quarantines', [
            'payload_quarantine_id' => $quarantineId,
            'status' => 'released',
        ]);
        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('governance.change_executions', [
            'change_request_uuid' => $changeUuid,
            'outcome' => 'success',
        ]);
        $this->assertSame(
            'RECOGNIZABLE-GOVERNED-QUARANTINE-PATIENT',
            app(ClinicalPayloadStore::class)->readJson($stored->payloadObjectId, $this->sourceId, 'raw_message')['patient'],
        );
        $this->assertStringNotContainsString(
            'RECOGNIZABLE-GOVERNED-QUARANTINE-PATIENT',
            DB::table('raw.payload_quarantine_events')->get()->toJson()
                .DB::table('governance.change_executions')->get()->toJson(),
        );
    }

    public function test_legal_hold_apply_and_release_are_exact_dual_controlled_actions(): void
    {
        $stored = app(ClinicalPayloadStore::class)->storeJson($this->sourceId, 'fhir_resource', [
            'resourceType' => 'Patient',
            'identifier' => 'RECOGNIZABLE-HOLD-PATIENT',
        ]);
        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $url = "/api/admin/integrations/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/hold-requests";
        $payload = [
            'operation' => 'apply',
            'hold_reason_code' => 'legal_case_2026_0713',
            'reason' => 'Apply the preservation hold after reviewing the exact source and object authority.',
        ];

        $this->selectScope($author)->postJson($url, $payload)
            ->assertStatus(428)->assertJsonPath('error.code', 'step_up_required');
        $applyUuid = $this->requestChange($author, $url, $payload, 'apply_clinical_payload_hold');
        $this->selectScope($author)->withSession($this->stepUp())->postJson($url, $payload)
            ->assertConflict()->assertJsonPath('error.code', 'change_request_already_open');
        $this->approve($approver, $applyUuid);
        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$applyUuid}/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/execute-hold",
        )->assertOk()->assertJsonPath('data.legalHold', true);

        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'legal_hold' => true,
        ]);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'hold_applied',
            'governed_change_request_uuid' => $applyUuid,
        ]);
        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/purge-requests",
            ['reason' => 'Attempt exceptional purge while the preservation hold remains active.'],
        )->assertConflict()->assertJsonPath('error.code', 'clinical_payload_legal_hold_active');

        $releasePayload = [
            'operation' => 'release',
            'hold_reason_code' => 'legal_case_2026_0713_closed',
            'reason' => 'Release the preservation hold after documented legal authorization and independent review.',
        ];
        $releaseUuid = $this->requestChange($author, $url, $releasePayload, 'release_clinical_payload_hold');
        $this->approve($approver, $releaseUuid);
        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$releaseUuid}/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/execute-hold",
        )->assertOk()->assertJsonPath('data.legalHold', false);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'hold_released',
            'governed_change_request_uuid' => $releaseUuid,
        ]);
    }

    public function test_exceptional_purge_requires_clear_dependencies_and_retains_governed_tombstone(): void
    {
        $stored = app(ClinicalPayloadStore::class)->storeJson($this->sourceId, 'writeback_draft', [
            'resourceType' => 'Task',
            'description' => 'RECOGNIZABLE-PURGE-PATIENT',
        ]);
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();
        $draftId = DB::table('ops.writeback_drafts')->insertGetId([
            'writeback_draft_uuid' => (string) Str::uuid(),
            'source_id' => $this->sourceId,
            'target_system' => 'governed-ehr',
            'resource_type' => 'Task',
            'draft_type' => 'fhir_task',
            'status' => 'pending_approval',
            'resource_payload' => '{}',
            'payload_object_id' => $stored->payloadObjectId,
            'routing_payload' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'writeback_draft_id');
        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $url = "/api/admin/integrations/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/purge-requests";
        $reason = ['reason' => 'Purge the exact encrypted object under the approved minimum-necessary exception.'];

        $this->selectScope($author)->withSession($this->stepUp())->postJson($url, $reason)
            ->assertConflict()->assertJsonPath('error.code', 'clinical_payload_deletion_blocked');
        DB::table('ops.writeback_drafts')->where('writeback_draft_id', $draftId)->update([
            'status' => 'cancelled',
            'updated_at' => now(),
        ]);
        $changeUuid = $this->requestChange($author, $url, $reason, 'purge_clinical_payload');
        $this->approve($approver, $changeUuid);
        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/execute-purge",
        )->assertOk()->assertJsonPath('data.status', 'deleted');

        $this->assertFalse(Storage::disk('clinical-payloads')->exists((string) $authority->object_key));
        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'status' => 'deleted',
        ]);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'purge_marked',
            'governed_change_request_uuid' => $changeUuid,
        ]);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'deleted',
            'governed_change_request_uuid' => $changeUuid,
        ]);
        $this->assertStringNotContainsString(
            'RECOGNIZABLE-PURGE-PATIENT',
            DB::table('raw.payload_object_events')->where('payload_object_id', $stored->payloadObjectId)->get()->toJson(),
        );
    }

    public function test_terminal_quarantine_purge_deletes_object_and_retains_exact_governance_evidence(): void
    {
        $stored = app(ClinicalPayloadStore::class)->storeJson($this->sourceId, 'raw_message', [
            'patient' => 'RECOGNIZABLE-TERMINAL-QUARANTINE-PATIENT',
        ]);
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();
        $quarantineId = app(ClinicalPayloadQuarantineService::class)->quarantine(
            $stored->payloadObjectId,
            $this->sourceId,
            'unsafe_content',
            'terminal_policy_rejection',
            'governance-test',
        );
        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $url = "/api/admin/integrations/sources/{$this->sourceId}/payload-quarantines/{$quarantineId}/purge-requests";
        $changeUuid = $this->requestChange($author, $url, [
            'reason' => 'Terminally purge the isolated encrypted object after independent policy review.',
        ], 'purge_quarantined_payload');
        $this->approve($approver, $changeUuid);
        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/payload-quarantines/{$quarantineId}/execute-purge",
        )->assertOk()->assertJsonPath('data.status', 'purged');

        $this->assertFalse(Storage::disk('clinical-payloads')->exists((string) $authority->object_key));
        $this->assertDatabaseHas('raw.payload_quarantines', ['payload_quarantine_id' => $quarantineId, 'status' => 'purged']);
        $this->assertDatabaseHas('raw.payload_objects', ['payload_object_id' => $stored->payloadObjectId, 'status' => 'deleted']);
        $this->assertDatabaseHas('raw.payload_quarantine_events', [
            'payload_quarantine_id' => $quarantineId,
            'event_type' => 'purged',
            'governed_change_request_uuid' => $changeUuid,
        ]);
    }

    public function test_integrity_recovery_only_verifies_an_exact_externally_restored_object(): void
    {
        $stored = app(ClinicalPayloadStore::class)->storeJson($this->sourceId, 'canonical_event', [
            'patient' => 'RECOGNIZABLE-RECOVERY-PATIENT',
        ]);
        $authority = DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->firstOrFail();
        $original = Storage::disk('clinical-payloads')->get((string) $authority->object_key);
        Storage::disk('clinical-payloads')->put((string) $authority->object_key, 'ZCP1-corrupt');
        try {
            app(ClinicalPayloadStore::class)->readJson($stored->payloadObjectId, $this->sourceId, 'canonical_event');
            $this->fail('Corrupt ciphertext was readable.');
        } catch (ClinicalPayloadException $exception) {
            $this->assertSame('clinical_payload_ciphertext_hash_mismatch', $exception->errorCode);
        }
        Storage::disk('clinical-payloads')->put((string) $authority->object_key, $original);

        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $url = "/api/admin/integrations/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/integrity-recovery-requests";
        $changeUuid = $this->requestChange($author, $url, [
            'reason' => 'Verify the externally restored immutable ciphertext and restore bounded access.',
        ], 'recover_clinical_payload_integrity');
        $this->approve($approver, $changeUuid);
        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/execute-integrity-recovery",
        )->assertOk()->assertJsonPath('data.status', 'ready');

        $this->assertSame(
            'RECOGNIZABLE-RECOVERY-PATIENT',
            app(ClinicalPayloadStore::class)->readJson($stored->payloadObjectId, $this->sourceId, 'canonical_event')['patient'],
        );
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'integrity_recovered',
            'governed_change_request_uuid' => $changeUuid,
        ]);
    }

    public function test_payload_execution_rejects_wrong_source_and_post_approval_state_drift(): void
    {
        $stored = app(ClinicalPayloadStore::class)->storeJson($this->sourceId, 'raw_message', ['event' => 'ADT-A01']);
        $otherSourceId = $this->additionalSource();
        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $url = "/api/admin/integrations/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/purge-requests";
        $changeUuid = $this->requestChange($author, $url, [
            'reason' => 'Approve exact-object purge before exercising drift and scope protections.',
        ], 'purge_clinical_payload');
        $this->approve($approver, $changeUuid);

        $this->selectScope($author, $otherSourceId)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$otherSourceId}/payload-objects/{$stored->payloadObjectId}/execute-purge",
        )->assertConflict()->assertJsonPath('error.code', 'admin_source_scope_mismatch');

        app(ClinicalPayloadStore::class)->markRetentionPending($stored->payloadObjectId, $this->sourceId);
        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/execute-purge",
        )->assertConflict()->assertJsonPath('error.code', 'approved_payload_mismatch');
        $this->assertTrue(Storage::disk('clinical-payloads')->exists(
            (string) DB::table('raw.payload_objects')->where('payload_object_id', $stored->payloadObjectId)->value('object_key'),
        ));
    }

    public function test_failed_external_deletion_commits_retryable_pending_state_and_failure_evidence(): void
    {
        $stored = app(ClinicalPayloadStore::class)->storeJson($this->sourceId, 'raw_message', ['event' => 'ADT-A03']);
        $author = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $approver = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $url = "/api/admin/integrations/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/purge-requests";
        $changeUuid = $this->requestChange($author, $url, [
            'reason' => 'Exercise fail-closed deletion evidence for the exact approved encrypted object.',
        ], 'purge_clinical_payload');
        $this->approve($approver, $changeUuid);

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('clinical-payloads')->andReturn($disk);

        $this->selectScope($author)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/payload-objects/{$stored->payloadObjectId}/execute-purge",
        )->assertStatus(503)->assertJsonPath('error.code', 'clinical_payload_storage_delete_failed');

        $this->assertDatabaseHas('raw.payload_objects', [
            'payload_object_id' => $stored->payloadObjectId,
            'status' => 'deletion_pending',
        ]);
        $this->assertDatabaseHas('raw.payload_object_events', [
            'payload_object_id' => $stored->payloadObjectId,
            'event_type' => 'deletion_failed',
            'to_status' => 'deletion_pending',
            'governed_change_request_uuid' => $changeUuid,
        ]);
        $this->assertDatabaseHas('governance.change_executions', [
            'change_request_uuid' => $changeUuid,
            'outcome' => 'failure',
        ]);
        $this->assertDatabaseMissing('governance.change_executions', [
            'change_request_uuid' => $changeUuid,
            'outcome' => 'success',
        ]);
    }

    /** @return array<string, int|string> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }

    private function requestChange(User $author, string $url, array $payload, string $expectedAction): string
    {
        return (string) $this->selectScope($author)->withSession($this->stepUp())->postJson($url, $payload)
            ->assertCreated()
            ->assertJsonPath('data.action', $expectedAction)
            ->assertJsonPath('data.status', 'pending_approval')
            ->json('data.changeRequestUuid');
    }

    private function approve(User $approver, string $changeUuid): void
    {
        $this->selectScope($approver)->withSession($this->stepUp())->postJson(
            "/api/admin/integrations/governed-changes/{$changeUuid}/decision",
            [
                'decision' => 'approved',
                'reason' => 'Independent reviewer approved the exact bounded clinical-payload contract.',
            ],
        )->assertOk()->assertJsonPath('data.decision', 'approved');
    }

    private function selectScope(User $user, ?int $sourceId = null): self
    {
        $sourceId ??= $this->sourceId;
        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $sourceId,
            'return_path' => '/integrations',
        ])->assertRedirect();

        return $this;
    }

    private function additionalSource(): int
    {
        return (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'payload.governance.other',
            'source_name' => 'Payload Governance Other Source',
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'PAYLOAD_GOVERNANCE_ORG',
            'facility_key' => 'PAYLOAD_GOVERNANCE_FACILITY',
            'system_class' => 'ehr',
            'environment' => 'testing',
            'interface_type' => 'hl7v2',
            'active_status' => 'testing',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
    }
}
