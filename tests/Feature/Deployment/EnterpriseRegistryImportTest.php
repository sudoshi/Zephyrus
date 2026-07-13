<?php

namespace Tests\Feature\Deployment;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Services\Auth\StepUpAuthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENT-REG — governed enterprise registry import (preview, conflict review,
 * dual-controlled commit, change history) and the source-activation readiness
 * gate for declared required service lines / locations.
 */
final class EnterpriseRegistryImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
    }

    public function test_preview_classifies_create_update_and_no_change(): void
    {
        $manager = $this->manager();
        Organization::create(['organization_key' => 'ENT_ONE', 'name' => 'Enterprise One', 'kind' => 'idn']);

        $payload = [
            'organizations' => [
                ['key' => 'ENT_ONE', 'name' => 'Enterprise One Renamed', 'external_identifiers' => ['npi' => '111']],
                ['key' => 'ENT_TWO', 'name' => 'Enterprise Two'],
            ],
        ];

        $response = $this->actingAs($manager)
            ->postJson('/admin/enterprise/import/preview', ['payload' => $payload])
            ->assertOk();

        $response->assertJsonPath('summary.create', 1)   // ENT_TWO
            ->assertJsonPath('summary.update', 1)          // ENT_ONE renamed
            ->assertJsonPath('summary.conflict', 0)
            ->assertJsonPath('readiness.committable', true);
        $this->assertSame(100, $response->json('readiness.score'));
    }

    public function test_preview_flags_external_identifier_conflicts_and_requires_resolution(): void
    {
        $manager = $this->manager();
        $existingId = DB::table('hosp_org.organizations')->insertGetId([
            'organization_key' => 'ENT_EXISTING',
            'name' => 'Existing',
            'kind' => 'idn',
            'external_identifiers' => json_encode(['npi' => '999']),
            'metadata' => '{}',
        ], 'organization_id');
        $this->assertIsInt($existingId);

        // A different natural key claiming the same external NPI is a conflict.
        $payload = [
            'organizations' => [
                ['key' => 'ENT_NEW', 'name' => 'New Org', 'external_identifiers' => ['npi' => '999']],
            ],
        ];

        $unresolved = $this->actingAs($manager)
            ->postJson('/admin/enterprise/import/preview', ['payload' => $payload])
            ->assertOk();
        $unresolved->assertJsonPath('summary.conflict', 1)
            ->assertJsonPath('unresolvedConflictCount', 1)
            ->assertJsonPath('readiness.committable', false);
        $conflictKey = $unresolved->json('conflicts.0.conflictKey');
        $this->assertSame('organizations:ENT_NEW', $conflictKey);

        // Resolving 'adopt' turns it into a normal create.
        $resolved = $this->actingAs($manager)
            ->postJson('/admin/enterprise/import/preview', [
                'payload' => $payload,
                'conflict_resolutions' => [$conflictKey => 'adopt'],
            ])
            ->assertOk();
        $resolved->assertJsonPath('summary.conflict', 0)
            ->assertJsonPath('summary.create', 1)
            ->assertJsonPath('unresolvedConflictCount', 0);
    }

    public function test_preview_authorization_allows_managers_and_denies_read_only_roles(): void
    {
        $payload = ['organizations' => [['key' => 'ENT_X', 'name' => 'X']]];

        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $this->actingAs($frontline)->postJson('/admin/enterprise/import/preview', ['payload' => $payload])
            ->assertForbidden();

        // 'admin' has viewEnterpriseSetup but NOT manageEnterpriseSetup — preview is
        // read-gated (viewDeploymentConsole), so admin may preview but not commit.
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $this->actingAs($admin)->postJson('/admin/enterprise/import/preview', ['payload' => $payload])
            ->assertOk();
        $this->actingAs($admin)->withSession($this->stepUp())
            ->postJson('/admin/enterprise/import/changes', ['payload' => $payload, 'change_reason' => 'admin should not commit imports'])
            ->assertForbidden();
    }

    public function test_governed_commit_requires_author_approver_separation_and_applies_with_history(): void
    {
        $author = $this->manager();
        $approver = $this->manager();

        $payload = [
            'organizations' => [
                ['key' => 'ENT_GOV', 'name' => 'Governed Enterprise', 'owner' => 'Jane Owner',
                    'steward' => 'Sam Steward', 'external_identifiers' => ['npi' => '424242'],
                    'source_of_truth' => 'authoritative_feed'],
            ],
        ];

        $create = $this->actingAs($author)->withSession($this->stepUp())
            ->postJson('/admin/enterprise/import/changes', [
                'payload' => $payload,
                'change_reason' => 'Import the governed enterprise organization row.',
            ])
            ->assertCreated();
        $changeUuid = $create->json('changeRequestUuid');

        // Nothing applied until approval + execution.
        $this->assertDatabaseMissing('hosp_org.organizations', ['organization_key' => 'ENT_GOV']);

        // Author cannot approve their own change.
        $this->actingAs($author)->withSession($this->stepUp())
            ->postJson("/admin/enterprise/import/changes/{$changeUuid}/decision", [
                'approve' => true, 'reason' => 'Trying to approve my own enterprise import.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'author_approver_conflict');

        $this->actingAs($approver)->withSession($this->stepUp())
            ->postJson("/admin/enterprise/import/changes/{$changeUuid}/decision", [
                'approve' => true, 'reason' => 'Independent approval of the enterprise import.',
            ])
            ->assertOk();

        // Apply must re-supply the exact payload; the service re-hashes and matches.
        $this->actingAs($approver)->withSession($this->stepUp())
            ->postJson("/admin/enterprise/import/changes/{$changeUuid}/apply", ['payload' => $payload])
            ->assertOk()
            ->assertJsonPath('applied.create', 1);

        $row = DB::table('hosp_org.organizations')->where('organization_key', 'ENT_GOV')->first();
        $this->assertNotNull($row);
        $this->assertSame('authoritative_feed', $row->source_of_truth);
        $this->assertSame('Jane Owner', $row->owner_name);
        $this->assertSame('Sam Steward', $row->steward_name);
        $this->assertNotNull($row->valid_from);
        $this->assertSame(['npi' => '424242'], json_decode((string) $row->external_identifiers, true));

        // Append-only change history recorded the create against the governed change.
        $this->assertDatabaseHas('hosp_org.enterprise_change_history', [
            'entity_type' => 'organization',
            'entity_natural_key' => 'ENT_GOV',
            'change_kind' => 'create',
            'governed_change_request_uuid' => $changeUuid,
        ]);
    }

    public function test_change_history_is_append_only(): void
    {
        DB::table('hosp_org.enterprise_change_history')->insert([
            'change_history_uuid' => (string) \Illuminate\Support\Str::uuid7(),
            'entity_type' => 'organization',
            'entity_natural_key' => 'ENT_LEDGER',
            'change_kind' => 'create',
            'source_of_truth' => 'import',
            'changed_fields' => '[]',
            'before_state' => '{}',
            'after_state' => '{}',
            'recorded_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('hosp_org.enterprise_change_history')
            ->where('entity_natural_key', 'ENT_LEDGER')
            ->update(['change_kind' => 'update']);
    }

    public function test_source_activation_blocks_on_unresolved_required_topology(): void
    {
        [$sourceId] = $this->readySource();

        // Declare a required service line and location that do not resolve.
        $this->actingAs($this->manager())
            ->putJson("/admin/enterprise/sources/{$sourceId}/required-topology", [
                'required_service_line_codes' => ['nonexistent_line'],
                'required_location_space_codes' => ['NO_SUCH_SPACE'],
            ])
            ->assertOk();

        $assessment = app(\App\Integrations\Healthcare\Services\SourceReadinessService::class)
            ->evaluate($sourceId, CarbonImmutable::now(), null, persist: false);

        $failed = collect($assessment['requirements'])->where('status', 'failed')->pluck('code')->all();
        $this->assertContains('enterprise.required_service_lines', $failed);
        $this->assertContains('enterprise.required_locations', $failed);
    }

    public function test_source_activation_passes_when_required_topology_resolves(): void
    {
        [$sourceId, $facilityKey] = $this->readySource();

        $spaceCode = 'ENT_SPACE_'.strtoupper($facilityKey);
        DB::table('hosp_space.facility_spaces')->insert([
            'space_code' => $spaceCode,
            'space_name' => 'Emergency Department',
            'space_category' => 'unit',
            'facility_key' => $facilityKey,
            'status' => 'active',
            'geometry' => '{}',
            'attributes' => '{}',
        ]);

        $this->actingAs($this->manager())
            ->putJson("/admin/enterprise/sources/{$sourceId}/required-topology", [
                'required_service_line_codes' => ['emergency'],
                'required_location_space_codes' => [$spaceCode],
            ])
            ->assertOk();

        $assessment = app(\App\Integrations\Healthcare\Services\SourceReadinessService::class)
            ->evaluate($sourceId, CarbonImmutable::now(), null, persist: false);

        $topologyChecks = collect($assessment['requirements'])
            ->whereIn('code', ['enterprise.required_service_lines', 'enterprise.required_locations']);
        $this->assertCount(2, $topologyChecks);
        $this->assertTrue($topologyChecks->every(fn (array $c): bool => $c['status'] === 'passed'));
    }

    /** @return array{0:int,1:string} sourceId + facilityKey */
    private function readySource(): array
    {
        $facilityKey = 'ENT_SRC_FACILITY';
        $organization = Organization::create(['organization_key' => 'ENT_SRC_IDN', 'name' => 'Source IDN', 'kind' => 'idn']);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => $facilityKey,
            'facility_name' => 'Source Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);

        $sourceId = (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'source_key' => 'ent.topology.source',
            'organization_id' => $organization->organization_id,
            'facility_id' => $facility->facility_id,
            'tenant_key' => $organization->organization_key,
            'facility_key' => $facility->facility_key,
            'source_name' => 'Enterprise Topology Source',
            'system_class' => 'ehr',
            'environment' => 'sandbox',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'go_live_status' => 'testing',
            'lifecycle_state' => 'validating',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');

        app(\App\Integrations\Healthcare\Services\SourceConfigurationVersionService::class)->initialize(
            $sourceId, null, 'Initial enterprise topology test configuration.', (string) \Illuminate\Support\Str::uuid(),
        );
        app(\App\Integrations\Healthcare\Services\SourceLifecycleService::class)->initialize(
            $sourceId, null, 'Initial enterprise topology test lifecycle.',
        );
        app(\App\Integrations\Healthcare\Services\SourceOnboardingService::class)->initialize($sourceId);

        return [$sourceId, $facilityKey];
    }

    private function manager(): User
    {
        return User::factory()->create([
            'role' => 'superuser',
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function stepUp(): array
    {
        return [
            StepUpAuthenticationService::VERIFIED_AT => time(),
            StepUpAuthenticationService::METHOD => 'password',
        ];
    }
}
