<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Services\NetworkRouteService;
use App\Integrations\Healthcare\Services\SourceConfigurationVersionService;
use App\Integrations\Healthcare\Services\SourceLifecycleService;
use App\Integrations\Healthcare\Services\SourceOnboardingService;
use App\Integrations\Healthcare\Services\SourceStatusFacetService;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Security\Network\DnsResolver;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GovernedSourceActivationApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sourceId;

    private int $organizationId;

    private int $facilityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'ACTIVATION_TEST_IDN',
            'name' => 'Activation Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'ACTIVATION_FACILITY',
            'facility_name' => 'Activation Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;

        $this->sourceId = (int) DB::table('integration.sources')->insertGetId([
            'source_uuid' => (string) Str::uuid(),
            'source_key' => 'governed.activation.test',
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'tenant_key' => 'ACTIVATION_TEST_IDN',
            'facility_key' => 'ACTIVATION_FACILITY',
            'source_name' => 'Governed Activation Test',
            'vendor' => 'Test Vendor',
            'system_class' => 'ehr',
            'environment' => 'production',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'ready',
            'lifecycle_state' => 'validating',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_id');
        app(SourceConfigurationVersionService::class)->initialize(
            $this->sourceId,
            null,
            'Initial governed activation test configuration.',
            (string) Str::uuid(),
        );
        app(SourceLifecycleService::class)->initialize(
            $this->sourceId,
            null,
            'Initial governed activation test lifecycle.',
        );
        $this->fakeDns(['fhir.test' => ['8.8.8.8']]);
        $this->completeOnboarding();
    }

    public function test_source_go_live_requires_step_up_independent_approval_and_exact_configuration(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);

        $this->selectScope($author)
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/activation-requests", [
                'reason' => 'Production readiness and rollback evidence are attached.',
            ])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'step_up_required');

        $changeUuid = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/activation-requests", [
                'reason' => 'Production readiness and rollback evidence are attached.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.action', 'activate_production_source')
            ->assertJsonPath('data.status', 'pending_approval')
            ->json('data.changeRequestUuid');

        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Attempting to approve my own production activation.',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'author_approver_conflict');

        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independent contract, BAA, readiness, and rollback review passed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.decision', 'approved');

        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/execute-source-activation")
            ->assertOk()
            ->assertJsonPath('data.activeStatus', 'active')
            ->assertJsonPath('data.goLiveStatus', 'live');

        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'active_status' => 'active',
            'go_live_status' => 'live',
        ]);
        $this->assertDatabaseHas('governance.change_executions', [
            'change_request_uuid' => $changeUuid,
            'outcome' => 'success',
        ]);
        $this->assertDatabaseHas('audit.user_events', [
            'action' => 'governance.change.executed',
            'target_type' => 'governed_change',
            'target_id' => $changeUuid,
        ]);
    }

    public function test_generic_configuration_endpoint_cannot_bypass_activation_governance(): void
    {
        $user = User::factory()->create(['role' => 'superuser']);

        $this->selectScope($user)->patchJson("/api/admin/integrations/sources/{$this->sourceId}", [
            'active_status' => 'active',
            'go_live_status' => 'live',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['active_status', 'go_live_status']);

        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $this->sourceId,
            'active_status' => 'testing',
            'go_live_status' => 'ready',
        ]);
    }

    public function test_configuration_drift_invalidates_an_approved_activation(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $changeUuid = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/activation-requests", [
                'reason' => 'Activation contract is ready for an independent review.',
            ])->assertCreated()->json('data.changeRequestUuid');
        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'The submitted activation contract was independently approved.',
            ])->assertOk();

        $currentVersionId = (int) DB::table('integration.sources')
            ->where('source_id', $this->sourceId)
            ->value('current_configuration_version_id');
        app(SourceConfigurationVersionService::class)->reviseAndApply(
            $this->sourceId,
            ['interface_type' => 'hl7v2'],
            $currentVersionId,
            $author->id,
            'Change the interface contract after activation approval.',
            (string) Str::uuid(),
        );

        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/execute-source-activation")
            ->assertConflict()
            ->assertJsonPath('error.code', 'approved_payload_mismatch');
        $this->assertDatabaseMissing('governance.change_executions', [
            'change_request_uuid' => $changeUuid,
            'outcome' => 'success',
        ]);
    }

    public function test_configuration_proposal_requires_independent_approval_and_applies_exact_version(): void
    {
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $currentVersionId = (int) DB::table('integration.sources')
            ->where('source_id', $this->sourceId)
            ->value('current_configuration_version_id');
        $proposal = $this->selectScope($author)
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/configuration-versions", [
                'source_name' => 'Governed Activation Test v2',
                'expected_configuration_version_id' => $currentVersionId,
                'change_reason' => 'Propose the reviewed vendor configuration for the next validation cycle.',
            ])->assertCreated()
            ->assertJsonPath('data.isEffective', false)
            ->assertJsonPath('data.versionNumber', 2)
            ->json('data');

        $this->assertSame(
            'Governed Activation Test',
            DB::table('integration.sources')->where('source_id', $this->sourceId)->value('source_name'),
        );

        $changeUuid = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson(
                "/api/admin/integrations/sources/{$this->sourceId}/configuration-versions/{$proposal['configurationVersionId']}/application-requests",
                ['reason' => 'Apply the independently reviewed immutable configuration proposal.'],
            )->assertCreated()
            ->assertJsonPath('data.action', 'apply_source_configuration')
            ->json('data.changeRequestUuid');

        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independent review confirmed the exact version hash and rollback path.',
            ])->assertOk();

        $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/execute-source-configuration")
            ->assertOk()
            ->assertJsonPath('data.sourceName', 'Governed Activation Test v2')
            ->assertJsonPath('data.currentConfigurationVersionId', $proposal['configurationVersionId'])
            ->assertJsonPath('data.lifecycleState', 'configured');

        $this->assertDatabaseHas('governance.change_executions', [
            'change_request_uuid' => $changeUuid,
            'outcome' => 'success',
        ]);
        $this->assertDatabaseHas('integration.source_lifecycle_events', [
            'source_id' => $this->sourceId,
            'configuration_version_id' => $proposal['configurationVersionId'],
            'from_state' => 'validating',
            'to_state' => 'configured',
            'governed_change_request_uuid' => $changeUuid,
        ]);
    }

    public function test_credential_rotation_requires_exact_payload_step_up_and_independent_approval(): void
    {
        $credentialId = (int) DB::table('integration.source_credentials')->insertGetId([
            'source_id' => $this->sourceId,
            'credential_key' => 'backend-services',
            'credential_type' => 'smart_backend_services',
            'secret_ref' => 'vault://zephyrus/integration/old-key',
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_credential_id');
        $author = User::factory()->create(['role' => 'superuser']);
        $approver = User::factory()->create(['role' => 'superuser']);
        $rotatesAt = now()->addMonth()->toDateString();
        $rotation = [
            'secret_ref' => 'vault://zephyrus/integration/new-key',
            'rotates_at' => $rotatesAt,
        ];

        $this->selectScope($author)->patchJson(
            "/api/admin/integrations/sources/{$this->sourceId}/credentials/{$credentialId}",
            $rotation,
        )->assertUnprocessable()->assertJsonValidationErrors(['credential']);

        $changeUuid = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/sources/{$this->sourceId}/credentials/{$credentialId}/rotation-requests", [
                ...$rotation,
                'reason' => 'Scheduled credential replacement before the current key expires.',
            ])->assertCreated()->assertJsonPath('data.action', 'rotate_integration_credential')
            ->json('data.changeRequestUuid');
        $this->selectScope($approver)->withSession($this->stepUp())
            ->postJson("/api/admin/integrations/governed-changes/{$changeUuid}/decision", [
                'decision' => 'approved',
                'reason' => 'Independent reviewer verified the replacement reference and rotation window.',
            ])->assertOk();

        $response = $this->selectScope($author)->withSession($this->stepUp())
            ->postJson(
                "/api/admin/integrations/governed-changes/{$changeUuid}/sources/{$this->sourceId}/credentials/{$credentialId}/execute-rotation",
                $rotation,
            )->assertOk()
            ->assertJsonPath('data.secretReferenceConfigured', true);
        $this->assertStringNotContainsString('vault://', $response->getContent());
        $this->assertSame(
            'vault://zephyrus/integration/new-key',
            DB::table('integration.source_credentials')->where('source_credential_id', $credentialId)->value('secret_ref'),
        );
        $this->assertDatabaseHas('governance.change_executions', [
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

    private function selectScope(User $user): self
    {
        $this->actingAs($user)->put('/admin/active-scope', [
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $this->sourceId,
            'return_path' => '/integrations',
        ])->assertRedirect();

        return $this;
    }

    private function completeOnboarding(): void
    {
        $endpointId = (int) DB::table('integration.source_endpoints')->insertGetId([
            'source_id' => $this->sourceId,
            'endpoint_type' => 'api_base',
            'url' => 'https://fhir.test/r4',
            'auth_type' => 'oauth2',
            'tls_mode' => 'system_ca',
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'source_endpoint_id');
        DB::table('integration.source_credentials')->insert([
            'source_id' => $this->sourceId,
            'credential_key' => 'readiness-credential',
            'credential_type' => 'oauth2_client',
            'secret_ref' => 'vault://zephyrus/integration/readiness',
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(NetworkRouteService::class)->create($this->sourceId, [
            'route_key' => 'fhir-public-egress',
            'source_endpoint_id' => $endpointId,
            'transport' => 'public_internet',
            'hostname' => 'fhir.test',
            'port' => 443,
            'dns_policy' => 'public_only',
            'allowed_ip_cidrs' => [],
            'egress_policy_key' => 'integration-https-egress',
            'mtls_required' => false,
            'change_reason' => 'Authorize the exact FHIR test endpoint for activation readiness.',
        ], null);
        $onboarding = app(SourceOnboardingService::class);
        $initial = $onboarding->initialize($this->sourceId);
        $onboarding->revise($this->sourceId, [
            'system_version' => '2026.1',
            'protocol_profile' => 'FHIR R4 + US Core 6.1',
            'owner_name' => 'Integration Owner',
            'steward_name' => 'Data Steward',
            'network_route_key' => 'private-link-east',
            'data_classification' => 'restricted_phi',
            'permitted_purpose' => 'Coordinate production clinical operations and patient flow.',
            'phi_permission_basis' => 'Executed BAA and minimum-necessary operations policy.',
            'retention_policy_key' => 'clinical-interface-7y',
            'retention_days' => 2555,
            'credential_strategy' => 'oauth2',
            'conformance_status' => 'passed',
            'support_entitlement' => 'critical',
            'vendor_support_identifier' => 'SUPPORT-TEST-001',
            'maintenance_timezone' => 'America/New_York',
            'contacts' => [
                ['role' => 'owner', 'name' => 'Integration Owner', 'email' => 'owner@example.test'],
                ['role' => 'steward', 'name' => 'Data Steward', 'email' => 'steward@example.test'],
                ['role' => 'escalation', 'name' => 'Command Center', 'email' => 'escalation@example.test'],
            ],
            'maintenance_windows' => [
                ['weekday' => 0, 'start_local' => '02:00', 'duration_minutes' => 60, 'purpose' => 'Vendor maintenance'],
            ],
            'slo_definition' => [
                'availability_percent' => 99.9,
                'freshness_minutes' => 15,
                'completeness_percent' => 99.9,
                'latency_ms' => 5000,
                'error_rate_percent' => 1,
                'acknowledgement_seconds' => 30,
                'reconciliation_variance_percent' => 1,
            ],
        ], (int) $initial->source_onboarding_version_id, null, 'Complete the production onboarding readiness profile.');

        foreach ([
            'contract' => 'verified',
            'baa' => 'verified',
            'dua' => 'not_required',
            'conformance_report' => 'verified',
            'vendor_approval' => 'verified',
            'customer_uat' => 'verified',
            'test_results' => 'verified',
            'security_review' => 'verified',
            'change_ticket' => 'verified',
            'cutover_plan' => 'verified',
            'rollback_plan' => 'verified',
        ] as $type => $status) {
            $onboarding->addEvidence($this->sourceId, [
                'evidence_type' => $type,
                'evidence_status' => $status,
                'display_label' => str_replace('_', ' ', ucfirst($type)).' test evidence',
                'reference_uri' => 'https://evidence.test/'.$type,
                'artifact_sha256' => str_repeat('a', 64),
                'issued_at' => now()->subDay(),
                'expires_at' => now()->addYear(),
                'reason' => 'Record reviewed production readiness evidence for testing.',
            ], null);
        }

        // INT-LIFECYCLE: the governed conformance/contract facets are separate
        // from onboarding evidence; the tightened activation gate requires them.
        $facets = app(SourceStatusFacetService::class);
        $facets->recordConformance(
            $this->sourceId,
            'passed',
            'fhir-r4-us-core',
            '6.1.0',
            'Vendor conformance verified for the production activation gate.',
            null,
        );
        $contractEvidenceId = (int) DB::table('integration.source_evidence_records')
            ->where('source_id', $this->sourceId)
            ->where('evidence_type', 'contract')
            ->where('evidence_status', 'verified')
            ->orderByDesc('source_evidence_record_id')
            ->value('source_evidence_record_id');
        $facets->recordContract(
            $this->sourceId,
            'active',
            $contractEvidenceId,
            'Executed contract entitlement present for the production activation gate.',
            null,
        );
    }

    /** @param array<string, list<string>> $answers */
    private function fakeDns(array $answers): void
    {
        $this->app->instance(DnsResolver::class, new class($answers) extends DnsResolver
        {
            /** @param array<string, list<string>> $answers */
            public function __construct(private readonly array $answers) {}

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? [];
            }
        });
    }
}
