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

        // Idempotent: updateOrCreate keyed on the unit abbreviation (and bed label
        // per unit) so this is safe to run inside DatabaseSeeder on every
        // `php artisan db:seed` without accumulating duplicate units/beds. Syncing
        // (not just creating) makes adopted legacy rows — e.g. an old plain
        // "5 East" — converge on the manifest branding, type and bed count.
        foreach ($units as $u) {
            $unit = Unit::updateOrCreate(
                ['abbreviation' => $u['abbr']],
                [
                    'name' => $u['name'],
                    'type' => $u['type'],
                    'staffed_bed_count' => $u['staffed_bed_count'],
                    'ratio_floor' => (int) round($u['nurse_ratio'] ?? 4),
                    'is_deleted' => false,
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

            // Adopted legacy units can carry more bed rows than the manifest staffs
            // (BedTrackingService counts bed ROWS, not staffed_bed_count). Soft-trim
            // the surplus, available beds only — never an occupied or dirty bed.
            $live = Bed::where('unit_id', $unit->unit_id)->where('is_deleted', false)->count();
            $surplus = $live - (int) $u['staffed_bed_count'];
            if ($surplus > 0) {
                Bed::where('unit_id', $unit->unit_id)
                    ->where('is_deleted', false)
                    ->where('status', 'available')
                    ->orderByDesc('bed_id')
                    ->limit($surplus)
                    ->update(['is_deleted' => true]);
            }
        }

        // Soft-delete any pre-existing units that are NOT in the manifest roster
        // (legacy 'SD', the old single 'ICU', etc.) so the live app shows only the
        // Summit 25. Additive/non-destructive: flips is_deleted, never DROPs rows.
        // virtual_home units are exempt: the Home Hospital virtual ward is
        // deliberately NOT in the manifest (its slots must never inflate
        // manifest-derived physical denominators) and is owned by
        // HomeHospitalDemoSeeder — see docs/home-hospital/HOME-HOSPITAL-BUILD-PROMPT.md.
        $manifestAbbrs = $manifest->unitAbbrs();
        Unit::whereNotIn('abbreviation', $manifestAbbrs)
            ->where('type', '!=', 'virtual_home')
            ->where('is_deleted', false)
            ->update(['is_deleted' => true]);

        // Seed default capability tags onto the freshly-created beds from each unit's
        // acuity/service line. Non-destructive: beds already carrying tags are untouched.
        app(CapabilityTagBackfiller::class)->backfillFromManifest();
    }
}
