<?php

namespace Database\Factories\Radiology\Concerns;

use App\Models\Ancillary\AncillaryOrder;
use Database\Factories\Ancillary\Concerns\CreatesAncillaryIntegrationFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesRadiologyFixtures
{
    use CreatesAncillaryIntegrationFixtures;

    protected function ensureRadiologyCatalogs(): void
    {
        foreach ([
            ['code' => 'XR', 'label' => 'Radiography', 'modality_group' => 'projection', 'is_cross_sectional' => false, 'supports_portable' => true, 'contrast_screening_applicable' => false],
            ['code' => 'CT', 'label' => 'Computed Tomography', 'modality_group' => 'cross_sectional', 'is_cross_sectional' => true, 'supports_portable' => false, 'contrast_screening_applicable' => true],
            ['code' => 'MRI', 'label' => 'Magnetic Resonance Imaging', 'modality_group' => 'cross_sectional', 'is_cross_sectional' => true, 'supports_portable' => false, 'contrast_screening_applicable' => true],
            ['code' => 'US', 'label' => 'Ultrasound', 'modality_group' => 'sonography', 'is_cross_sectional' => false, 'supports_portable' => true, 'contrast_screening_applicable' => false],
            ['code' => 'NM', 'label' => 'Nuclear Medicine', 'modality_group' => 'molecular', 'is_cross_sectional' => false, 'supports_portable' => false, 'contrast_screening_applicable' => false],
            ['code' => 'IR', 'label' => 'Interventional Radiology', 'modality_group' => 'interventional', 'is_cross_sectional' => false, 'supports_portable' => false, 'contrast_screening_applicable' => true],
        ] as $modality) {
            DB::table('hosp_ref.rad_modalities')->updateOrInsert(['code' => $modality['code']], [
                ...$modality, 'is_active' => true, 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([['code' => 'general', 'label' => 'General Radiology'], ['code' => 'neuro', 'label' => 'Neuroradiology'], ['code' => 'body', 'label' => 'Body Imaging'], ['code' => 'msk', 'label' => 'Musculoskeletal Imaging'], ['code' => 'ir', 'label' => 'Interventional Radiology']] as $subspecialty) {
            DB::table('hosp_ref.rad_subspecialties')->updateOrInsert(['code' => $subspecialty['code']], [
                ...$subspecialty, 'is_active' => true, 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function createEncounterId(): int
    {
        return (int) DB::table('prod.encounters')->insertGetId([
            'patient_ref' => 'radiology-patient-'.Str::uuid(),
            'admitted_at' => now()->subHours(2),
            'acuity_tier' => 2,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'encounter_id');
    }

    protected function createRadiologyOrderId(): int
    {
        $encounterId = $this->createEncounterId();

        return (int) AncillaryOrder::factory()->radiology()->create([
            'encounter_id' => $encounterId,
            'encounter_ref' => 'encounter-'.$encounterId,
        ])->ancillary_order_id;
    }
}
