<?php

namespace Database\Factories\Lab\Concerns;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Lab\LabTestCatalog;
use Database\Factories\Ancillary\Concerns\CreatesAncillaryIntegrationFixtures;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesLabFixtures
{
    use CreatesAncillaryIntegrationFixtures;

    protected function ensureLabCatalogs(): void
    {
        if (! LabTestCatalog::query()->exists()) {
            app(AncillaryReferenceSeeder::class)->run();
        }
    }

    protected function catalog(string $catalogKey): LabTestCatalog
    {
        $this->ensureLabCatalogs();

        return LabTestCatalog::query()->where('catalog_key', $catalogKey)->firstOrFail();
    }

    protected function createEncounterId(): int
    {
        return (int) DB::table('prod.encounters')->insertGetId([
            'patient_ref' => 'lab-patient-'.Str::uuid(),
            'admitted_at' => now()->subHours(4),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'encounter_id');
    }

    protected function createLabOrderId(): int
    {
        return $this->createOrderId('lab');
    }

    protected function createPathologyOrderId(): int
    {
        return $this->createOrderId('pathology');
    }

    protected function createBloodBankOrderId(): int
    {
        return $this->createOrderId('blood_bank');
    }

    private function createOrderId(string $department): int
    {
        $encounterId = $this->createEncounterId();
        $sourceClass = match ($department) {
            'pathology' => 'ap_lis',
            'blood_bank' => 'blood_bank',
            default => 'lis',
        };
        $state = match ($department) {
            'pathology' => 'pathology',
            'blood_bank' => 'bloodBank',
            default => 'lab',
        };

        return (int) AncillaryOrder::factory()->{$state}()->create([
            'source_id' => $this->createIntegrationSourceId($sourceClass),
            'encounter_id' => $encounterId,
            'encounter_ref' => "encounter-{$encounterId}",
        ])->ancillary_order_id;
    }
}
