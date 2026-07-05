<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Unit;
use App\Services\Deployment\CapabilityTagBackfiller;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Database\Seeder;

class RtdcSeeder extends Seeder
{
    /**
     * Seed the full Summit Regional unit roster (all 25 manifest units — 23
     * inpatient + ED + PERIOP) and their beds from the HospitalManifest single
     * source of truth. No unit/bed-count literals live here anymore.
     */
    public function run(): void
    {
        $manifest = app(HospitalManifest::class);
        $units = $manifest->units();

        // Idempotent: firstOrCreate keyed on natural keys (unit abbreviation,
        // bed label per unit) so this is safe to run inside DatabaseSeeder on
        // every `php artisan db:seed` without accumulating duplicate units/beds.
        foreach ($units as $u) {
            $unit = Unit::firstOrCreate(
                ['abbreviation' => $u['abbr']],
                [
                    'name' => $u['name'],
                    'abbreviation' => $u['abbr'],
                    'type' => $u['type'],
                    'staffed_bed_count' => $u['staffed_bed_count'],
                    'ratio_floor' => (int) round($u['nurse_ratio'] ?? 4),
                ]
            );

            for ($i = 1; $i <= $u['staffed_bed_count']; $i++) {
                Bed::firstOrCreate(
                    [
                        'unit_id' => $unit->unit_id,
                        'label' => sprintf('%s-%02d', $unit->abbreviation, $i),
                    ],
                    [
                        'status' => 'available',
                        'isolation_capable' => $i % 8 === 0,
                    ]
                );
            }
        }

        // Soft-delete any pre-existing units that are NOT in the manifest roster
        // (legacy 'SD', the old single 'ICU', etc.) so the live app shows only the
        // Summit 25. Additive/non-destructive: flips is_deleted, never DROPs rows.
        $manifestAbbrs = $manifest->unitAbbrs();
        Unit::whereNotIn('abbreviation', $manifestAbbrs)
            ->where('is_deleted', false)
            ->update(['is_deleted' => true]);

        // Seed default capability tags onto the freshly-created beds from each unit's
        // acuity/service line. Non-destructive: beds already carrying tags are untouched.
        app(CapabilityTagBackfiller::class)->backfillFromManifest();
    }
}
