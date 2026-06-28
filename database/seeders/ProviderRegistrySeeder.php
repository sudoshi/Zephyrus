<?php

namespace Database\Seeders;

use App\Support\Hospital\HospitalManifest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the clinical provider registry (prod.providers) and the service-line
 * catalog (prod.services / prod.specialties) from the HospitalManifest single
 * source of truth (config/hospital/hospital-1.php) — never from hardcoded
 * clinician or service literals.
 *
 * Runs BETWEEN RtdcSeeder and CommandCenterDemoSeeder so that the full manifest
 * roster (30 named attendings/surgeons/anesthesiologists/intensivists/
 * hospitalists, NPIs 17000000xx) plus every service line exists before CCDS
 * layers its operational demo data on top. CCDS keys its own specialty/service
 * rows on `code` with firstOrCreate-style guards, so any overlap is reused, not
 * duplicated.
 *
 * Idempotent: providers are keyed on the unique `npi`; specialties and services
 * are keyed on `code`. Re-running is a no-op.
 *
 * Schema notes (2024_01_29_163500/163600 migrations):
 *   - prod.providers.specialty_id is NOT NULL with an FK to
 *     prod.specialties.specialty_id, so each provider resolves/creates its
 *     specialty row first.
 *   - prod.providers.type carries a CHECK constraint
 *     (type IN ('surgeon','anesthesiologist','nurse')); manifest roles are
 *     mapped onto those allowed values (anesthesiologist passes through, every
 *     other physician role maps to 'surgeon').
 */
class ProviderRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $manifest = app(HospitalManifest::class);

        // ---- Service lines -> prod.specialties + prod.services -------------
        // The manifest provider `specialty` values are service-line codes, so a
        // specialty row per service line guarantees every provider can resolve
        // its NOT NULL specialty_id FK.
        $specialtyIdByCode = [];
        foreach ($manifest->serviceLines() as $line) {
            $code = $this->specialtyCode($line['code']);

            $existing = DB::table('prod.specialties')->where('code', $code)->first();
            if ($existing) {
                $specialtyIdByCode[$line['code']] = $existing->specialty_id;
            } else {
                $specialtyIdByCode[$line['code']] = DB::table('prod.specialties')->insertGetId([
                    'name' => $line['name'],
                    'code' => $code,
                    'active_status' => true,
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ], 'specialty_id');
            }

            // Services catalog mirrors the service-line set.
            $serviceCode = $this->serviceCode($line['code']);
            $existingService = DB::table('prod.services')->where('code', $serviceCode)->first();
            if (! $existingService) {
                DB::table('prod.services')->insert([
                    'name' => $line['name'],
                    'code' => $serviceCode,
                    'active_status' => true,
                    'created_by' => 'seeder',
                    'modified_by' => 'seeder',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'is_deleted' => false,
                ]);
            }
        }

        // ---- Providers -> prod.providers (keyed on unique npi) -------------
        foreach ($manifest->providers() as $provider) {
            if (DB::table('prod.providers')->where('npi', $provider['npi'])->exists()) {
                continue;
            }

            // Resolve the specialty FK; fall back to creating a specialty from
            // the provider's specialty code if it is not a known service line.
            $specialtyId = $specialtyIdByCode[$provider['specialty']]
                ?? $this->resolveSpecialtyId($provider['specialty']);

            DB::table('prod.providers')->insert([
                'name' => $provider['name'],
                'npi' => $provider['npi'],
                'specialty_id' => $specialtyId,
                'type' => $this->providerType($provider['role'] ?? ''),
                'active_status' => true,
                'created_by' => 'seeder',
                'modified_by' => 'seeder',
                'created_at' => now(),
                'updated_at' => now(),
                'is_deleted' => false,
            ]);
        }
    }

    /**
     * Maps a manifest provider `role` onto the prod.providers.type CHECK
     * constraint domain {surgeon, anesthesiologist, nurse}. Anesthesiologists
     * pass through; every other physician role (intensivist, attending,
     * hospitalist, surgeon, ...) is recorded as 'surgeon'.
     */
    private function providerType(string $role): string
    {
        return $role === 'anesthesiologist' ? 'anesthesiologist' : 'surgeon';
    }

    /** Resolve (or create) a specialty row for an ad-hoc specialty code. */
    private function resolveSpecialtyId(string $specialty): int
    {
        $code = $this->specialtyCode($specialty);

        $existing = DB::table('prod.specialties')->where('code', $code)->first();
        if ($existing) {
            return $existing->specialty_id;
        }

        return DB::table('prod.specialties')->insertGetId([
            'name' => Str::headline($specialty),
            'code' => $code,
            'active_status' => true,
            'created_by' => 'seeder',
            'modified_by' => 'seeder',
            'created_at' => now(),
            'updated_at' => now(),
            'is_deleted' => false,
        ], 'specialty_id');
    }

    private function specialtyCode(string $lineCode): string
    {
        return strtoupper($lineCode);
    }

    private function serviceCode(string $lineCode): string
    {
        return 'SVC_'.strtoupper($lineCode);
    }
}
