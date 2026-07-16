<?php

namespace Tests\Unit\Security;

use App\Integrations\Healthcare\Exceptions\IntegrationCredentialException;
use App\Security\Secrets\Providers\AwsSecretsManagerProvider;
use App\Security\Secrets\Providers\AzureKeyVaultProvider;
use App\Security\Secrets\Providers\FileSecretProvider;
use App\Security\Secrets\Providers\GcpSecretManagerProvider;
use App\Security\Secrets\Providers\VaultSecretProvider;
use App\Security\Secrets\SecretReferenceUri;
use App\Security\Secrets\SecretValueSelector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecretProvidersTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_file_provider_enforces_root_permissions_and_returns_only_safe_metadata(): void
    {
        $this->directory = storage_path('framework/secret-provider-'.Str::random(8));
        mkdir($this->directory, 0700, true);
        $path = $this->directory.'/runtime.key';
        file_put_contents($path, 'high-value-runtime-secret');
        chmod($path, 0640);
        config([
            'integrations.secret_file_root' => $this->directory,
            'integrations.secret_providers.file.enabled' => true,
        ]);

        $resolved = (new FileSecretProvider)->resolve(SecretReferenceUri::parse('file://'.$path));

        $this->assertSame('high-value-runtime-secret', $resolved->value());
        $this->assertSame('file', $resolved->providerScheme);
        $this->assertNotNull($resolved->providerVersion);
        $this->assertStringNotContainsString('high-value-runtime-secret', json_encode($resolved->safeMetadata(), JSON_THROW_ON_ERROR));

        chmod($path, 0644);
        $this->expectCredentialError('credential_file_permissions_unsafe');
        (new FileSecretProvider)->resolve(SecretReferenceUri::parse('file://'.$path));
    }

    public function test_vault_kv2_provider_resolves_an_explicit_field_and_lease_without_redirects(): void
    {
        config([
            'integrations.secret_providers.vault' => [
                'enabled' => true,
                'base_url' => 'https://vault.example.test',
                'token' => 'deployment-token',
                'namespace' => 'clinical',
                'kv_version' => 2,
                'allowed_mounts' => ['clinical-secrets'],
            ],
        ]);
        Http::fake([
            'https://vault.example.test/v1/clinical-secrets/data/epic/backend' => Http::response([
                'lease_duration' => 300,
                'renewable' => true,
                'data' => [
                    'data' => ['private_key' => 'vault-private-key', 'certificate' => 'vault-certificate'],
                    'metadata' => ['version' => 7, 'deletion_time' => '', 'destroyed' => false],
                ],
            ]),
        ]);

        $resolved = (new VaultSecretProvider(new SecretValueSelector))->resolve(
            SecretReferenceUri::parse('vault://clinical-secrets/epic/backend#private_key'),
        );

        $this->assertSame('vault-private-key', $resolved->value());
        $this->assertSame('7', $resolved->providerVersion);
        $this->assertTrue($resolved->leaseExpiresAt?->isFuture());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Vault-Token', 'deployment-token')
            && $request->hasHeader('X-Vault-Namespace', 'clinical'));
    }

    public function test_aws_provider_signs_get_secret_value_and_selects_a_json_field(): void
    {
        config([
            'integrations.secret_providers.aws-secretsmanager' => [
                'enabled' => true,
                'access_key_id' => 'AKIATESTACCESS',
                'secret_access_key' => 'test-secret-signing-key',
                'session_token' => 'test-session-token',
                'allowed_regions' => ['us-east-1'],
                'endpoint_template' => 'https://secretsmanager.%s.amazonaws.com',
            ],
        ]);
        Http::fake([
            'https://secretsmanager.us-east-1.amazonaws.com' => Http::response([
                'SecretString' => json_encode(['client_secret' => 'aws-provider-secret'], JSON_THROW_ON_ERROR),
                'VersionId' => 'version-0001',
                'VersionStages' => ['AWSCURRENT'],
            ]),
        ]);

        $resolved = (new AwsSecretsManagerProvider(new SecretValueSelector))->resolve(
            SecretReferenceUri::parse('aws-secretsmanager://us-east-1/zephyrus/epic#client_secret'),
        );

        $this->assertSame('aws-provider-secret', $resolved->value());
        $this->assertSame('version-0001', $resolved->providerVersion);
        Http::assertSent(function (Request $request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            return $request->hasHeader('X-Amz-Target', 'secretsmanager.GetSecretValue')
                && $request->hasHeader('X-Amz-Security-Token', 'test-session-token')
                && str_contains($authorization, 'AWS4-HMAC-SHA256 Credential=AKIATESTACCESS/')
                && ($request->data()['SecretId'] ?? null) === 'zephyrus/epic';
        });
    }

    public function test_gcp_and_azure_access_token_paths_return_versioned_expiring_metadata(): void
    {
        config([
            'integrations.secret_providers.gcp-secretmanager' => [
                'enabled' => true,
                'access_token' => 'gcp-deployment-token',
                'credentials_file' => null,
                'allowed_projects' => ['zephyrus-prod'],
                'api_base_url' => 'https://secretmanager.googleapis.com',
            ],
            'integrations.secret_providers.azure-keyvault' => [
                'enabled' => true,
                'access_token' => 'azure-deployment-token',
                'tenant_id' => null,
                'client_id' => null,
                'client_secret' => null,
                'allowed_vaults' => ['zephyrus-prod-vault'],
                'authority_host' => 'https://login.microsoftonline.com',
                'vault_dns_suffix' => 'vault.azure.net',
            ],
        ]);
        $azureVersion = str_repeat('a', 32);
        Http::fake([
            'https://secretmanager.googleapis.com/v1/projects/zephyrus-prod/secrets/epic-key/versions/5:access' => Http::response([
                'name' => 'projects/zephyrus-prod/secrets/epic-key/versions/5',
                'payload' => [
                    'data' => base64_encode('gcp-provider-secret'),
                    'dataCrc32c' => (string) hexdec(hash('crc32c', 'gcp-provider-secret')),
                ],
            ]),
            "https://zephyrus-prod-vault.vault.azure.net/secrets/epic-key/{$azureVersion}?api-version=7.4" => Http::response([
                'value' => 'azure-provider-secret',
                'id' => "https://zephyrus-prod-vault.vault.azure.net/secrets/epic-key/{$azureVersion}",
                'attributes' => ['enabled' => true, 'exp' => now()->addHour()->timestamp, 'managed' => true],
                'contentType' => 'application/x-pem-file',
            ]),
        ]);

        $gcp = (new GcpSecretManagerProvider(new SecretValueSelector))->resolve(
            SecretReferenceUri::parse('gcp-secretmanager://zephyrus-prod/epic-key/5'),
        );
        $azure = (new AzureKeyVaultProvider(new SecretValueSelector))->resolve(
            SecretReferenceUri::parse("azure-keyvault://zephyrus-prod-vault/epic-key/{$azureVersion}"),
        );

        $this->assertSame('gcp-provider-secret', $gcp->value());
        $this->assertSame('5', $gcp->providerVersion);
        $this->assertSame('azure-provider-secret', $azure->value());
        $this->assertSame($azureVersion, $azure->providerVersion);
        $this->assertTrue($azure->expiresAt?->isFuture());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'secretmanager.googleapis.com')
            && $request->hasHeader('Authorization', 'Bearer gcp-deployment-token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'vault.azure.net')
            && $request->hasHeader('Authorization', 'Bearer azure-deployment-token'));
    }

    public function test_reference_parser_rejects_credentials_queries_traversal_and_unsafe_selectors(): void
    {
        foreach ([
            'vault://user:password@clinical/path',
            'vault://clinical/path?version=1',
            'vault://clinical/../root',
            'vault://clinical/path#unsafe/selector',
        ] as $reference) {
            try {
                SecretReferenceUri::parse($reference);
                $this->fail("Unsafe reference was accepted: {$reference}");
            } catch (IntegrationCredentialException $exception) {
                $this->assertStringStartsWith('credential_reference_', $exception->errorCode);
            }
        }
    }

    private function expectCredentialError(string $code): void
    {
        $this->expectException(IntegrationCredentialException::class);
        $this->expectExceptionMessage($code);
    }
}
