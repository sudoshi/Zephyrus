<?php

namespace Tests\Feature\Integrations;

use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Security\Network\DnsResolver;
use App\Security\Network\IntegrationUrlPolicy;
use App\Security\Network\UnsafeIntegrationUrl;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationConfigurationApiTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $facilityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'INTEGRATION_TEST_IDN',
            'name' => 'Integration Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'INTEGRATION_TEST_FACILITY',
            'facility_name' => 'Integration Test Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        config()->set('integrations.network.allowed_hosts', [
            'fhir.vendor.example',
            'api.vendor.example',
            'redirect.vendor.example',
        ]);
        config()->set('integrations.network.allowed_ports', [443]);
        config()->set('integrations.network.require_https', true);
        config()->set('integrations.network.require_dns_resolution', true);
        $this->fakeDns([
            'fhir.vendor.example' => ['8.8.8.8'],
            'api.vendor.example' => ['8.8.4.4'],
            'redirect.vendor.example' => ['1.1.1.1'],
        ]);
    }

    public function test_source_endpoint_and_credential_crud_is_audited_and_secret_safe(): void
    {
        $user = $this->user('superuser');
        $correlationId = '018f89a4-3c76-7a0d-a7ac-9e102f51c237';

        $source = $this->selectScope($user)
            ->withHeader('X-Request-ID', $correlationId)
            ->postJson('/api/admin/integrations/sources', $this->sourcePayload())
            ->assertCreated()
            ->assertJsonPath('data.sourceKey', 'epic.fhir.production')
            ->assertJsonPath('data.baseUrlConfigured', true)
            ->assertJsonPath('data.baseUrlOrigin', 'https://fhir.vendor.example')
            ->json('data');
        $sourceId = (int) $source['sourceId'];
        $this->selectScope($user, $sourceId);

        $endpoint = $this->actingAs($user)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/endpoints", [
                'endpoint_type' => 'fhir_base',
                'url' => 'https://api.vendor.example/r4/acme?internal-routing=blue',
                'auth_type' => 'smart_backend',
                'tls_mode' => 'mtls',
                'is_active' => true,
                'owner' => 'Interoperability',
                'expected_cadence_minutes' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.urlOrigin', 'https://api.vendor.example')
            ->json('data');
        $endpointId = (int) $endpoint['endpointId'];

        $this->actingAs($user)
            ->patchJson("/api/admin/integrations/sources/{$sourceId}/endpoints/{$endpointId}", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $credential = $this->actingAs($user)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/credentials", [
                'credential_key' => 'smart-backend',
                'credential_type' => 'smart_backend_services',
                'secret_ref' => 'vault://zephyrus/epic/client-key',
                'certificate_ref' => 'vault://zephyrus/epic/client-certificate',
                'rotates_at' => now()->addMonth()->toDateString(),
                'owner' => 'Security Engineering',
            ])
            ->assertCreated()
            ->assertJsonPath('data.secretReferenceConfigured', true)
            ->assertJsonPath('data.certificateReferenceConfigured', true)
            ->json('data');
        $credentialId = (int) $credential['credentialId'];

        $configuration = $this->actingAs($user)
            ->getJson('/api/admin/integrations/control-plane')
            ->assertOk()
            ->assertJsonPath('data.credentials.0.credentialType', 'smart_backend_services');
        $serialized = $configuration->getContent();
        $this->assertStringNotContainsString('/r4/acme', $serialized);
        $this->assertStringNotContainsString('internal-routing', $serialized);
        $this->assertStringNotContainsString('vault://zephyrus/epic/client-key', $serialized);
        $this->assertStringNotContainsString('vault://zephyrus/epic/client-certificate', $serialized);

        $audits = DB::table('integration.configuration_audits')->orderBy('configuration_audit_id')->get();
        $this->assertCount(4, $audits);
        $this->assertSame($user->id, (int) $audits[0]->actor_user_id);
        $this->assertSame($correlationId, $audits[0]->correlation_id);
        $auditPayload = $audits->pluck('after_payload')->implode(' ');
        $this->assertStringNotContainsString('internal-routing', $auditPayload);
        $this->assertStringNotContainsString('vault://zephyrus', $auditPayload);

        $this->actingAs($user)
            ->patchJson("/api/admin/integrations/sources/{$sourceId}/credentials/{$credentialId}", [
                'secret_ref' => null,
                'certificate_ref' => null,
                'jwks_uri' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['secret_ref']);
        $this->assertNotNull(DB::table('integration.source_credentials')->where('source_credential_id', $credentialId)->value('secret_ref'));

        $this->actingAs($user)
            ->deleteJson("/api/admin/integrations/sources/{$sourceId}/credentials/{$credentialId}", [
                'reason' => 'Revoke the test credential after configuration verification.',
            ])
            ->assertNoContent();
        $this->assertDatabaseHas('integration.source_credentials', [
            'source_credential_id' => $credentialId,
            'credential_state' => 'revoked',
            'is_active' => false,
        ]);
        $this->actingAs($user)
            ->deleteJson("/api/admin/integrations/sources/{$sourceId}/endpoints/{$endpointId}")
            ->assertNoContent();
        $this->actingAs($user)
            ->deleteJson("/api/admin/integrations/sources/{$sourceId}", [
                'reason' => 'Retire the completed integration test source configuration.',
            ])
            ->assertOk()
            ->assertJsonPath('data.activeStatus', 'disabled')
            ->assertJsonPath('data.goLiveStatus', 'retired');

        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $sourceId,
            'active_status' => 'disabled',
            'go_live_status' => 'retired',
        ]);
        $this->assertSame(7, DB::table('integration.configuration_audits')->count());
    }

    public function test_plain_admin_and_ops_leader_cannot_read_or_mutate_configuration(): void
    {
        foreach (['admin', 'ops-leader', 'user'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->getJson('/api/admin/integrations/sources')->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/integrations/sources', $this->sourcePayload())->assertForbidden();
        }
    }

    public function test_raw_secrets_and_unapproved_secret_references_are_rejected(): void
    {
        $user = $this->user('superuser');
        $sourceId = $this->createSource($user);

        $this->actingAs($user)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/credentials", [
                'credential_key' => 'unsafe',
                'credential_type' => 'oauth2_client',
                'client_secret' => 'do-not-store-me',
                'secret_ref' => 'https://secrets.example/key',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_secret', 'secret_ref']);

        $this->assertSame(0, DB::table('integration.source_credentials')->count());
        $this->assertDatabaseMissing('integration.configuration_audits', ['entity_type' => 'credential_reference']);
    }

    public function test_url_policy_rejects_non_https_unallowlisted_and_private_targets(): void
    {
        $user = $this->user('superuser');
        $this->selectScope($user);

        $cases = [
            'http' => 'http://fhir.vendor.example/r4',
            'unallowlisted' => 'https://unapproved.example/r4',
            'localhost' => 'https://localhost/r4',
            'embedded_credentials' => 'https://user:password@fhir.vendor.example/r4',
            'unsafe_port' => 'https://fhir.vendor.example:8443/r4',
            'fragment' => 'https://fhir.vendor.example/r4#secret',
        ];

        foreach ($cases as $case => $url) {
            $payload = $this->sourcePayload(['base_url' => $url, 'source_key' => 'test.'.$case]);
            $this->actingAs($user)
                ->postJson('/api/admin/integrations/sources', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['base_url']);
        }

        $restricted = [
            'private.vendor.example' => '10.20.30.40',
            'loopback.vendor.example' => '127.0.0.1',
            'linklocal.vendor.example' => '169.254.10.20',
            'metadata.vendor.example' => '169.254.169.254',
        ];
        config()->set('integrations.network.allowed_hosts', array_keys($restricted));
        $this->fakeDns(collect($restricted)->map(fn (string $address): array => [$address])->all());
        foreach ($restricted as $host => $address) {
            $key = explode('.', $host)[0].'.target';
            $this->actingAs($user)
                ->postJson('/api/admin/integrations/sources', $this->sourcePayload([
                    'source_key' => $key,
                    'base_url' => "https://{$host}/r4",
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['base_url']);
        }

        config()->set('integrations.network.allowed_hosts', ['unresolved.vendor.example']);
        $this->fakeDns([]);
        $this->actingAs($user)
            ->postJson('/api/admin/integrations/sources', $this->sourcePayload([
                'source_key' => 'unresolved.target',
                'base_url' => 'https://unresolved.vendor.example/r4',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_url']);

        $this->assertSame(0, DB::table('integration.sources')->count());
    }

    public function test_url_policy_rejects_unsafe_redirect_chains(): void
    {
        $policy = app(IntegrationUrlPolicy::class);

        $this->expectException(UnsafeIntegrationUrl::class);
        $policy->assertSafeRedirectChain(
            'https://fhir.vendor.example/r4',
            ['https://redirect.vendor.example/r4'],
        );
    }

    public function test_configuration_audit_rows_are_database_enforced_append_only(): void
    {
        $this->createSource($this->user('superuser'));
        $auditId = (int) DB::table('integration.configuration_audits')->value('configuration_audit_id');

        $this->expectException(QueryException::class);
        DB::table('integration.configuration_audits')->where('configuration_audit_id', $auditId)->update([
            'action' => 'tampered',
        ]);
    }

    public function test_source_updates_create_exact_immutable_versions_and_reset_validation(): void
    {
        $user = $this->user('superuser');
        $sourceId = $this->createSource($user);
        $source = $this->actingAs($user)
            ->getJson("/api/admin/integrations/sources/{$sourceId}")
            ->assertOk()
            ->assertJsonPath('data.lifecycleState', 'validating')
            ->assertJsonPath('data.currentConfigurationVersionNumber', 1)
            ->json('data');

        $this->actingAs($user)
            ->patchJson("/api/admin/integrations/sources/{$sourceId}", [
                'source_name' => 'Epic FHIR Production v2',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_configuration_version_id', 'change_reason']);

        $updated = $this->actingAs($user)
            ->patchJson("/api/admin/integrations/sources/{$sourceId}", [
                'source_name' => 'Epic FHIR Production v2',
                'base_url' => 'https://fhir.vendor.example/r4/tenant-b?route=private',
                'expected_configuration_version_id' => $source['currentConfigurationVersionId'],
                'change_reason' => 'Move the validated connector to the approved tenant B route.',
            ])->assertOk()
            ->assertJsonPath('data.sourceName', 'Epic FHIR Production v2')
            ->assertJsonPath('data.lifecycleState', 'configured')
            ->assertJsonPath('data.currentConfigurationVersionNumber', 2)
            ->json('data');

        $history = $this->actingAs($user)
            ->getJson("/api/admin/integrations/sources/{$sourceId}/configuration-versions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.isEffective', true)
            ->assertJsonPath('data.0.changedFields.0', 'sourceName')
            ->assertJsonPath('data.0.changedFields.1', 'baseUrl')
            ->json('data');
        $serialized = json_encode($history, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('/r4/tenant-b', $serialized);
        $this->assertStringNotContainsString('route=private', $serialized);
        $this->assertNotSame($source['currentConfigurationSha256'], $updated['currentConfigurationSha256']);

        $this->actingAs($user)
            ->getJson("/api/admin/integrations/sources/{$sourceId}/lifecycle-events")
            ->assertOk()
            ->assertJsonPath('data.0.fromState', 'validating')
            ->assertJsonPath('data.0.toState', 'configured')
            ->assertJsonPath('data.0.configurationVersionNumber', 2);
    }

    public function test_versioned_source_projection_cannot_be_mutated_outside_the_ledger(): void
    {
        $sourceId = $this->createSource($this->user('superuser'));

        $this->expectException(QueryException::class);
        DB::table('integration.sources')->where('source_id', $sourceId)->update([
            'vendor' => 'Tampered vendor',
        ]);
    }

    public function test_configuration_versions_are_database_enforced_append_only(): void
    {
        $sourceId = $this->createSource($this->user('superuser'));
        $versionId = (int) DB::table('integration.sources')->where('source_id', $sourceId)
            ->value('current_configuration_version_id');

        $this->expectException(QueryException::class);
        DB::table('integration.source_configuration_versions')
            ->where('source_configuration_version_id', $versionId)
            ->update(['change_reason' => 'Attempted ledger tampering should always be rejected.']);
    }

    public function test_lifecycle_transitions_are_explicit_reasoned_and_separate_from_health(): void
    {
        $user = $this->user('superuser');
        $sourceId = $this->createSource($user);

        $this->actingAs($user)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/lifecycle-transitions", [
                'to_state' => 'configured',
                'reason' => 'Return the connector to configuration after validation findings.',
            ])->assertOk()
            ->assertJsonPath('data.lifecycleState', 'configured')
            ->assertJsonPath('data.activeStatus', 'inactive')
            ->assertJsonPath('data.goLiveStatus', 'planning');

        $this->actingAs($user)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/lifecycle-transitions", [
                'to_state' => 'live',
                'reason' => 'Attempt to bypass the governed activation boundary.',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['to_state']);

        $this->assertDatabaseHas('integration.source_lifecycle_events', [
            'source_id' => $sourceId,
            'from_state' => 'validating',
            'to_state' => 'configured',
        ]);
        $this->assertDatabaseHas('integration.sources', [
            'source_id' => $sourceId,
            'protocol_health_status' => 'unobserved',
        ]);
    }

    public function test_lifecycle_events_are_database_enforced_append_only(): void
    {
        $sourceId = $this->createSource($this->user('superuser'));
        $eventId = (int) DB::table('integration.source_lifecycle_events')
            ->where('source_id', $sourceId)
            ->value('source_lifecycle_event_id');

        $this->expectException(QueryException::class);
        DB::table('integration.source_lifecycle_events')
            ->where('source_lifecycle_event_id', $eventId)
            ->delete();
    }

    private function createSource(User $user): int
    {
        $sourceId = (int) $this->selectScope($user)
            ->postJson('/api/admin/integrations/sources', $this->sourcePayload())
            ->assertCreated()
            ->json('data.sourceId');
        $this->selectScope($user, $sourceId);

        return $sourceId;
    }

    /** @param array<string, mixed> $overrides */
    private function sourcePayload(array $overrides = []): array
    {
        return array_merge([
            'source_key' => 'epic.fhir.production',
            'source_name' => 'Epic FHIR Production',
            'vendor' => 'Epic',
            'system_class' => 'ehr',
            'environment' => 'production',
            'base_url' => 'https://fhir.vendor.example/r4/tenant-a?route=internal',
            'interface_type' => 'fhir_r4',
            'active_status' => 'testing',
            'fhir_version' => '4.0.1',
            'smart_supported' => true,
            'bulk_supported' => false,
            'subscriptions_supported' => false,
            'contract_status' => 'executed',
            'baa_status' => 'executed',
            'phi_allowed' => true,
            'go_live_status' => 'testing',
            'owner' => 'Interoperability',
            'expected_cadence_minutes' => 5,
        ], $overrides);
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
        $this->app->forgetInstance(IntegrationUrlPolicy::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => false]);
    }

    private function selectScope(User $user, ?int $sourceId = null): self
    {
        $this->actingAs($user)->put('/admin/active-scope', array_filter([
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $sourceId,
            'return_path' => '/integrations',
        ], fn (mixed $value): bool => $value !== null))->assertRedirect();

        return $this;
    }
}
