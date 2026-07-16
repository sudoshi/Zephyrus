<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CredentialNetworkGovernanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_smart_reference_is_backfilled_into_versioned_runtime_authority(): void
    {
        $migration = require database_path('migrations/2026_07_13_000700_create_credential_and_network_governance.php');
        $migration->down();
        $source = app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'legacy.smart.backfill',
            'source_name' => 'Legacy SMART Backfill',
            'vendor' => 'Epic',
            'system_class' => 'ehr',
            'environment' => 'sandbox',
            'interface_type' => 'fhir_r4',
        ]);
        $smartId = (int) DB::table('integration.smart_backend_credentials')->insertGetId([
            'credential_uuid' => (string) Str::uuid(),
            'source_id' => $source->source_id,
            'credential_key' => 'epic-smart-backend',
            'status' => 'configured',
            'client_id' => 'registered-client',
            'jwks_secret_ref' => 'vault://clinical/legacy-smart-key',
            'token_url' => 'https://fhir.vendor.example/oauth/token',
            'scope_payload' => '[]',
            'metadata' => '{}',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ], 'smart_backend_credential_id');
        $placeholderId = (int) DB::table('integration.source_credentials')->insertGetId([
            'source_id' => $source->source_id,
            'credential_key' => 'legacy-disabled-placeholder',
            'credential_type' => 'oauth2_client',
            'secret_ref' => null,
            'certificate_ref' => null,
            'jwks_uri' => null,
            'rotates_at' => null,
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ], 'source_credential_id');

        $migration->up();

        $smart = DB::table('integration.smart_backend_credentials')
            ->where('smart_backend_credential_id', $smartId)->firstOrFail();
        $this->assertNotNull($smart->source_credential_id);
        $authority = DB::table('integration.source_credentials')
            ->where('source_credential_id', $smart->source_credential_id)->firstOrFail();
        $version = DB::table('integration.source_credential_versions')
            ->where('source_credential_version_id', $authority->current_credential_version_id)->firstOrFail();
        $this->assertSame((int) $source->source_id, (int) $authority->source_id);
        $this->assertSame('smart_backend_services', $authority->credential_type);
        $this->assertSame('active', $authority->credential_state);
        $this->assertSame('vault://clinical/legacy-smart-key', $authority->secret_ref);
        $this->assertSame((int) $authority->source_credential_id, (int) $version->source_credential_id);
        $this->assertSame(1, (int) $version->version_number);
        $this->assertDatabaseHas('integration.source_credentials', [
            'source_credential_id' => $placeholderId,
            'credential_state' => 'disabled',
            'is_active' => false,
        ]);
        $this->assertNotNull(DB::table('integration.source_credentials')
            ->where('source_credential_id', $placeholderId)
            ->value('current_credential_version_id'));

        DB::beginTransaction();
        try {
            DB::table('integration.smart_backend_credentials')
                ->where('smart_backend_credential_id', $smartId)
                ->update(['jwks_secret_ref' => 'vault://clinical/drift']);
            $this->fail('The legacy SMART projection accepted drift from governed authority.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('must match its governed credential projection', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
