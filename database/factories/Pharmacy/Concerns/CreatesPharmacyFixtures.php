<?php

namespace Database\Factories\Pharmacy\Concerns;

use App\Models\Ancillary\AncillaryOrder;
use App\Models\Pharmacy\FormularyItem;
use Database\Factories\Ancillary\Concerns\CreatesAncillaryIntegrationFixtures;
use Database\Seeders\AncillaryReferenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesPharmacyFixtures
{
    use CreatesAncillaryIntegrationFixtures;

    protected function ensureRxFormulary(): void
    {
        if (! FormularyItem::query()->exists()) {
            app(AncillaryReferenceSeeder::class)->run();
        }
    }

    protected function formulary(string $formularyKey): FormularyItem
    {
        $this->ensureRxFormulary();

        return FormularyItem::query()->where('formulary_key', $formularyKey)->firstOrFail();
    }

    protected function createEncounterId(): int
    {
        return (int) DB::table('prod.encounters')->insertGetId([
            'patient_ref' => 'rx-patient-'.Str::uuid(),
            'admitted_at' => now()->subHours(4),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'encounter_id');
    }

    protected function createUnitId(): int
    {
        return (int) DB::table('prod.units')->insertGetId([
            'name' => 'Rx Unit '.Str::uuid()->toString(),
            'abbreviation' => 'RXU',
            'type' => 'med_surg',
            'staffed_bed_count' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'unit_id');
    }

    protected function createPharmacyOrderId(): int
    {
        $encounterId = $this->createEncounterId();

        return (int) AncillaryOrder::factory()->pharmacy()->create([
            'source_id' => $this->createIntegrationSourceId('pharmacy'),
            'encounter_id' => $encounterId,
            'encounter_ref' => "encounter-{$encounterId}",
        ])->ancillary_order_id;
    }
}
