<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Integrations\Healthcare\Exceptions\IntegrationProtocolException;
use App\Integrations\Healthcare\Services\CredentialAuthorityService;
use App\Integrations\Healthcare\Services\CredentialRuntimeResolver;
use App\Integrations\Healthcare\Services\IntegrationConnectionGuard;
use App\Models\Org\Facility;
use App\Models\Org\Organization;
use App\Models\User;
use App\Security\Network\DnsResolver;
use App\Security\Network\IntegrationUrlPolicy;
use App\Security\Secrets\ResolvedSecret;
use App\Security\Secrets\SecretProvider;
use App\Security\Secrets\SecretProviderRegistry;
use App\Security\Secrets\SecretReferenceUri;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CredentialAndNetworkGovernanceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    private int $facilityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('deployment:seed-registry')->assertExitCode(0);
        $organization = Organization::create([
            'organization_key' => 'CREDENTIAL_NETWORK_TEST_IDN',
            'name' => 'Credential Network Test IDN',
            'kind' => 'idn',
        ]);
        $facility = Facility::create([
            'organization_id' => $organization->organization_id,
            'facility_key' => 'CREDENTIAL_NETWORK_FACILITY',
            'facility_name' => 'Credential Network Facility',
            'idn_role' => 'community_hospital',
            'review_status' => 'client_verified',
            'is_active' => true,
        ]);
        $this->organizationId = (int) $organization->organization_id;
        $this->facilityId = (int) $facility->facility_id;
        config([
            'integrations.network.allowed_hosts' => ['fhir.governed.example', 'proxy.governed.example'],
            'integrations.network.allowed_ports' => [443],
            'integrations.network.require_dns_resolution' => true,
        ]);
        $this->fakeDns([
            'fhir.governed.example' => ['8.8.8.8'],
            'proxy.governed.example' => ['1.1.1.1'],
        ]);
    }

    public function test_admin_api_exposes_provider_capability_versions_validations_and_routes_without_secrets_or_addresses(): void
    {
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $sourceId = $this->createSource($user);
        $endpointId = (int) $this->selectScope($user, $sourceId)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/endpoints", [
                'endpoint_type' => 'fhir_base',
                'url' => 'https://fhir.governed.example/r4/acme?route=internal',
                'auth_type' => 'smart_backend',
                'tls_mode' => 'system_ca',
                'is_active' => true,
            ])->assertCreated()->json('data.endpointId');
        $credentialId = (int) $this->postJson("/api/admin/integrations/sources/{$sourceId}/credentials", [
            'credential_key' => 'smart-runtime',
            'credential_type' => 'smart_backend_services',
            'secret_ref' => 'vault://clinical/epic/backend#private_key',
            'rotates_at' => now()->addMonths(6)->toIso8601String(),
            'owner' => 'Identity Platform',
        ])->assertCreated()
            ->assertJsonPath('data.credentialState', 'active')
            ->assertJsonPath('data.secretReferenceConfigured', true)
            ->json('data.credentialId');

        $providers = $this->getJson('/api/admin/integrations/secret-providers')->assertOk()->json('data');
        $this->assertSame(
            ['aws-secretsmanager', 'azure-keyvault', 'file', 'gcp-secretmanager', 'vault'],
            collect($providers)->pluck('scheme')->all(),
        );
        $versions = $this->getJson("/api/admin/integrations/sources/{$sourceId}/credentials/{$credentialId}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.versionNumber', 1)
            ->assertJsonPath('data.0.secretProviderScheme', 'vault');
        $this->assertStringNotContainsString('clinical/epic/backend', $versions->getContent());

        $validation = $this->postJson("/api/admin/integrations/sources/{$sourceId}/credentials/{$credentialId}/validations")
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.providerScheme', 'vault')
            ->assertJsonPath('data.providerVersion', 'test-version-1');
        $this->assertStringNotContainsString('test-secret-value', $validation->getContent());
        $this->assertStringNotContainsString('clinical/epic/backend', $validation->getContent());

        $baseRoute = [
            'route_key' => 'fhir-primary-egress',
            'source_endpoint_id' => $endpointId,
            'transport' => 'public_internet',
            'hostname' => 'fhir.governed.example',
            'port' => 443,
            'proxy_url' => 'https://proxy.governed.example:443',
            'dns_policy' => 'allowlist',
            'allowed_ip_cidrs' => ['8.8.8.0/24'],
            'egress_policy_key' => 'integration-https-egress',
            'mtls_required' => false,
            'server_name' => 'fhir.governed.example',
            'change_reason' => 'Authorize the exact production FHIR endpoint through controlled egress.',
        ];
        $this->postJson("/api/admin/integrations/sources/{$sourceId}/network-routes", [
            ...$baseRoute,
            'allowed_ip_cidrs' => ['0.0.0.0/0'],
        ])->assertUnprocessable()->assertJsonValidationErrors('allowed_ip_cidrs');
        $this->postJson("/api/admin/integrations/sources/{$sourceId}/network-routes", [
            ...$baseRoute,
            'proxy_url' => 'https://proxy.governed.example:443/egress/internal',
        ])->assertUnprocessable()->assertJsonValidationErrors('proxy_url');
        $this->postJson("/api/admin/integrations/sources/{$sourceId}/network-routes", [
            ...$baseRoute,
            'server_name' => 'other.governed.example',
        ])->assertUnprocessable()->assertJsonValidationErrors('server_name');

        $route = $this->postJson("/api/admin/integrations/sources/{$sourceId}/network-routes", $baseRoute)->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.proxyOrigin', 'https://proxy.governed.example:443')
            ->assertJsonPath('data.lastAddressCount', 2)
            ->assertJsonMissing(['8.8.8.8'])
            ->assertJsonMissing(['1.1.1.1'])
            ->json('data');

        $serialized = $this->getJson('/api/admin/integrations/control-plane')->assertOk()->getContent();
        $this->assertStringNotContainsString('test-secret-value', $serialized);
        $this->assertStringNotContainsString('clinical/epic/backend', $serialized);
        $this->assertStringNotContainsString('/r4/acme', $serialized);
        $this->assertStringNotContainsString('route=internal', $serialized);
        $this->assertStringNotContainsString('8.8.8.8', $serialized);
        $this->assertStringNotContainsString('1.1.1.1', $serialized);
        $this->assertDatabaseHas('integration.configuration_audits', [
            'entity_type' => 'network_route',
            'entity_id' => $route['networkRouteId'],
            'action' => 'created',
        ]);
        $this->assertSame(1, DB::table('integration.credential_validation_observations')->count());
        $this->assertSame(1, DB::table('integration.network_route_observations')->count());
    }

    public function test_connection_time_dns_rebinding_blocks_a_previously_validated_route_and_stores_only_fingerprints(): void
    {
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $sourceId = $this->createSource($user);
        $endpointId = (int) $this->selectScope($user, $sourceId)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/endpoints", [
                'endpoint_type' => 'fhir_base',
                'url' => 'https://fhir.governed.example/r4',
                'is_active' => true,
            ])->assertCreated()->json('data.endpointId');
        $routeId = (int) $this->postJson("/api/admin/integrations/sources/{$sourceId}/network-routes", [
            'route_key' => 'rebind-protected-route',
            'source_endpoint_id' => $endpointId,
            'transport' => 'public_internet',
            'hostname' => 'fhir.governed.example',
            'port' => 443,
            'dns_policy' => 'public_only',
            'egress_policy_key' => 'integration-https-egress',
            'change_reason' => 'Validate the public endpoint before exercising DNS rebinding controls.',
        ])->assertCreated()->json('data.networkRouteId');

        $this->fakeDns(['fhir.governed.example' => ['10.20.30.40']]);
        $this->postJson("/api/admin/integrations/sources/{$sourceId}/network-routes/{$routeId}/validations")
            ->assertCreated()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.lastErrorCode', 'network_public_address_required');
        try {
            app(IntegrationConnectionGuard::class)->guard($sourceId, 'https://fhir.governed.example/r4');
            $this->fail('A rebound private address must be rejected at connection time.');
        } catch (IntegrationProtocolException $exception) {
            $this->assertSame('network_public_address_required', $exception->errorCode);
        }

        $dump = DB::table('integration.network_route_observations')->get()->toJson();
        $this->assertStringNotContainsString('8.8.8.8', $dump);
        $this->assertStringNotContainsString('10.20.30.40', $dump);
        $this->assertDatabaseHas('integration.source_network_routes', [
            'source_network_route_id' => $routeId,
            'status' => 'blocked',
        ]);
    }

    public function test_runtime_falls_back_to_the_previous_version_only_inside_an_explicit_rotation_overlap(): void
    {
        $this->app->instance(SecretProviderRegistry::class, new SecretProviderRegistry([
            new class implements SecretProvider
            {
                public function scheme(): string
                {
                    return 'vault';
                }

                public function enabled(): bool
                {
                    return true;
                }

                public function validateReference(SecretReferenceUri $reference): void
                {
                    if ($reference->host !== 'clinical' || $reference->pathWithoutLeadingSlash() === '') {
                        throw new IntegrationCredentialException('test_reference_invalid');
                    }
                }

                public function resolve(SecretReferenceUri $reference): ResolvedSecret
                {
                    $this->validateReference($reference);
                    if (str_contains($reference->pathWithoutLeadingSlash(), 'current-fails')) {
                        throw new IntegrationCredentialException('test_current_provider_failure');
                    }

                    return new ResolvedSecret('previous-version-runtime-value', 'vault', 'previous-version');
                }
            },
        ]));
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $sourceId = $this->createSource($user);
        $credentialId = (int) $this->selectScope($user, $sourceId)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/credentials", [
                'credential_key' => 'overlap-runtime',
                'credential_type' => 'smart_backend_services',
                'secret_ref' => 'vault://clinical/previous-works',
            ])->assertCreated()->json('data.credentialId');
        $overlapEnds = CarbonImmutable::now()->addMinutes(15);
        app(CredentialAuthorityService::class)->rotate(
            $credentialId,
            [
                'secret_ref' => 'vault://clinical/current-fails',
                'rotation_overlap_ends_at' => $overlapEnds,
                'is_active' => true,
            ],
            $user->id,
            'Exercise a bounded fallback while the new provider version is unavailable.',
            null,
        );

        $resolved = app(CredentialRuntimeResolver::class)->resolveSecret($sourceId, $credentialId);

        $this->assertSame('previous-version-runtime-value', $resolved->value());
        $this->assertNotNull(DB::table('integration.source_credentials')
            ->where('source_credential_id', $credentialId)->value('last_used_at'));
        $this->assertCount(2, app(CredentialAuthorityService::class)->history($sourceId, $credentialId));
        $this->assertStringNotContainsString(
            'previous-version-runtime-value',
            DB::table('integration.source_credential_versions')->get()->toJson(),
        );

        CarbonImmutable::setTestNow($overlapEnds->addSecond());
        try {
            app(CredentialRuntimeResolver::class)->resolveSecret($sourceId, $credentialId);
            $this->fail('Previous-version fallback must end with the governed overlap window.');
        } catch (IntegrationCredentialException $exception) {
            $this->assertSame('test_current_provider_failure', $exception->errorCode);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_authority_and_observation_ledgers_reject_mutation_and_projection_drift(): void
    {
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $sourceId = $this->createSource($user);
        $credentialId = (int) $this->selectScope($user, $sourceId)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/credentials", [
                'credential_key' => 'immutable-authority',
                'credential_type' => 'oauth2_client',
                'secret_ref' => 'vault://clinical/immutable',
            ])->assertCreated()->json('data.credentialId');
        $this->postJson("/api/admin/integrations/sources/{$sourceId}/credentials/{$credentialId}/validations")
            ->assertCreated();

        foreach ([
            ['integration.source_credential_versions', 'source_credential_version_id', 'created_at'],
            ['integration.credential_validation_observations', 'credential_validation_observation_id', 'observed_at'],
        ] as [$table, $key, $timestamp]) {
            $id = DB::table($table)->max($key);
            DB::beginTransaction();
            try {
                DB::table($table)->where($key, $id)->update([$timestamp => now()->addMinute()]);
                $this->fail("{$table} accepted an update.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
        }
        DB::beginTransaction();
        try {
            DB::table('integration.source_credentials')
                ->where('source_credential_id', $credentialId)
                ->update(['secret_ref' => 'vault://clinical/drift']);
            $this->fail('The credential projection accepted drift outside immutable authority.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('must exactly match', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_mtls_route_validates_a_matching_pair_and_supplies_only_ephemeral_curl_blobs(): void
    {
        [$certificatePem, $privateKeyPem] = $this->clientCertificatePair();
        $this->app->instance(SecretProviderRegistry::class, new SecretProviderRegistry([
            new class($certificatePem, $privateKeyPem) implements SecretProvider
            {
                public function __construct(
                    private readonly string $certificatePem,
                    private readonly string $privateKeyPem,
                ) {}

                public function scheme(): string
                {
                    return 'vault';
                }

                public function enabled(): bool
                {
                    return true;
                }

                public function validateReference(SecretReferenceUri $reference): void
                {
                    if ($reference->host !== 'clinical' || ! in_array($reference->pathWithoutLeadingSlash(), ['client-certificate', 'client-key'], true)) {
                        throw new IntegrationCredentialException('test_mtls_reference_invalid');
                    }
                }

                public function resolve(SecretReferenceUri $reference): ResolvedSecret
                {
                    $this->validateReference($reference);

                    return new ResolvedSecret(
                        $reference->pathWithoutLeadingSlash() === 'client-certificate'
                            ? $this->certificatePem
                            : $this->privateKeyPem,
                        'vault',
                        'mtls-test-version',
                    );
                }
            },
        ]));
        $user = User::factory()->create(['role' => 'superuser', 'must_change_password' => false]);
        $sourceId = $this->createSource($user);
        $endpointId = (int) $this->selectScope($user, $sourceId)
            ->postJson("/api/admin/integrations/sources/{$sourceId}/endpoints", [
                'endpoint_type' => 'fhir_base',
                'url' => 'https://fhir.governed.example/r4',
                'auth_type' => 'mtls',
                'tls_mode' => 'mtls',
                'is_active' => true,
            ])->assertCreated()->json('data.endpointId');
        $credentialId = (int) $this->postJson("/api/admin/integrations/sources/{$sourceId}/credentials", [
            'credential_key' => 'fhir-mtls-client',
            'credential_type' => 'mtls',
            'secret_ref' => 'vault://clinical/client-key',
            'certificate_ref' => 'vault://clinical/client-certificate',
        ])->assertCreated()->json('data.credentialId');
        $routeId = (int) $this->postJson("/api/admin/integrations/sources/{$sourceId}/network-routes", [
            'route_key' => 'fhir-mtls-egress',
            'source_endpoint_id' => $endpointId,
            'transport' => 'public_internet',
            'hostname' => 'fhir.governed.example',
            'port' => 443,
            'dns_policy' => 'public_only',
            'egress_policy_key' => 'integration-https-egress',
            'mtls_required' => true,
            'client_credential_id' => $credentialId,
            'change_reason' => 'Authorize the matching mTLS client authority for the FHIR route.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'validated')
            ->json('data.networkRouteId');

        $options = app(IntegrationConnectionGuard::class)
            ->guard($sourceId, 'https://fhir.governed.example/r4')
            ->httpOptions();

        $this->assertSame($certificatePem, $options['curl'][CURLOPT_SSLCERT_BLOB]);
        $this->assertSame($privateKeyPem, $options['curl'][CURLOPT_SSLKEY_BLOB]);
        $this->assertDatabaseHas('integration.source_network_routes', [
            'source_network_route_id' => $routeId,
            'mtls_required' => true,
            'client_credential_id' => $credentialId,
        ]);
        $databaseDump = json_encode([
            DB::table('integration.source_credentials')->get(),
            DB::table('integration.credential_validation_observations')->get(),
            DB::table('integration.network_route_observations')->get(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $databaseDump);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $databaseDump);
    }

    private function createSource(User $user): int
    {
        $this->selectScope($user);

        return (int) $this->postJson('/api/admin/integrations/sources', [
            'source_key' => 'governance.test.'.strtolower(str()->random(8)),
            'source_name' => 'Credential Network Governance Test',
            'vendor' => 'Test Vendor',
            'system_class' => 'ehr',
            'environment' => 'production',
            'base_url' => 'https://fhir.governed.example/r4',
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
            'owner' => 'Integration Governance',
            'expected_cadence_minutes' => 5,
        ])->assertCreated()->json('data.sourceId');
    }

    /** @return array{string, string} */
    private function clientCertificatePair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'zephyrus-mtls-client.example'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($key, $privateKeyPem));

        return [$certificatePem, $privateKeyPem];
    }

    private function selectScope(User $user, ?int $sourceId = null): self
    {
        $this->actingAs($user)->put('/admin/active-scope', array_filter([
            'organization_id' => $this->organizationId,
            'facility_id' => $this->facilityId,
            'source_id' => $sourceId,
            'return_path' => '/integrations?tab=credentials',
        ], fn (mixed $value): bool => $value !== null))->assertRedirect();

        return $this;
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
}
