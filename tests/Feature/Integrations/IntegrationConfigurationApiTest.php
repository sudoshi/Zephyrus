<?php

namespace Tests\Feature\Integrations;

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

    protected function setUp(): void
    {
        parent::setUp();
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

        $source = $this->actingAs($user)
            ->withHeader('X-Request-ID', $correlationId)
            ->postJson('/api/admin/integrations/sources', $this->sourcePayload())
            ->assertCreated()
            ->assertJsonPath('data.sourceKey', 'epic.fhir.production')
            ->assertJsonPath('data.baseUrlConfigured', true)
            ->assertJsonPath('data.baseUrlOrigin', 'https://fhir.vendor.example')
            ->json('data');
        $sourceId = (int) $source['sourceId'];

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
            ->deleteJson("/api/admin/integrations/sources/{$sourceId}/credentials/{$credentialId}")
            ->assertNoContent();
        $this->actingAs($user)
            ->deleteJson("/api/admin/integrations/sources/{$sourceId}/endpoints/{$endpointId}")
            ->assertNoContent();
        $this->actingAs($user)
            ->deleteJson("/api/admin/integrations/sources/{$sourceId}")
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

    private function createSource(User $user): int
    {
        return (int) $this->actingAs($user)
            ->postJson('/api/admin/integrations/sources', $this->sourcePayload())
            ->assertCreated()
            ->json('data.sourceId');
    }

    /** @param array<string, mixed> $overrides */
    private function sourcePayload(array $overrides = []): array
    {
        return array_merge([
            'source_key' => 'epic.fhir.production',
            'source_name' => 'Epic FHIR Production',
            'tenant_key' => 'default',
            'facility_key' => 'SUMMIT_REGIONAL',
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
}
