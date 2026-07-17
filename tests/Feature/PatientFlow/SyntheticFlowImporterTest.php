<?php

namespace Tests\Feature\PatientFlow;

use App\Integrations\Healthcare\Services\SourceRegistryService;
use App\Models\Integration\Source;
use App\Services\PatientFlow\SyntheticFlowImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyntheticFlowImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_import_provisions_an_unscoped_source_in_a_non_live_state(): void
    {
        $this->runEmptyImport();

        $source = Source::query()->where('source_key', 'synthetic-flow-ehr')->sole();

        $this->assertNull($source->organization_id);
        $this->assertNull($source->facility_id);
        $this->assertSame('testing', $source->active_status);
        $this->assertSame('testing', $source->go_live_status);
        $this->assertSame('validating', $source->lifecycle_state);
        $this->assertFalse($source->phi_allowed);
        $this->assertSame('patient-flow-4d-navigator-demo', $source->metadata['purpose']);
    }

    public function test_synthetic_refresh_preserves_governance_metadata_and_non_live_state(): void
    {
        app(SourceRegistryService::class)->ensureSource([
            'source_key' => 'synthetic-flow-ehr',
            'tenant_key' => 'default',
            'facility_key' => 'ZEPHYRUS-500',
            'source_name' => 'Synthetic Flow EHR',
            'vendor' => 'synthetic',
            'system_class' => 'ehr',
            'environment' => 'sandbox',
            'interface_type' => 'hl7v2_file',
            'active_status' => 'testing',
            'contract_status' => 'not_required',
            'baa_status' => 'not_required',
            'phi_allowed' => false,
            'go_live_status' => 'testing',
            'metadata' => [
                'purpose' => 'patient-flow-4d-navigator-demo',
                'enterprise_scope_cutover' => [
                    'reason' => 'Synthetic demo sources stay non-live until canonical scope is selected.',
                ],
            ],
        ]);

        $this->runEmptyImport();

        $source = Source::query()->where('source_key', 'synthetic-flow-ehr')->sole();

        $this->assertSame('testing', $source->active_status);
        $this->assertSame('testing', $source->go_live_status);
        $this->assertSame('validating', $source->lifecycle_state);
        $this->assertSame(
            'Synthetic demo sources stay non-live until canonical scope is selected.',
            $source->metadata['enterprise_scope_cutover']['reason'],
        );
    }

    private function runEmptyImport(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zephyrus-synthetic-flow-');
        $this->assertNotFalse($path);

        try {
            app(SyntheticFlowImporter::class)->import($path);
        } finally {
            @unlink($path);
        }
    }
}
