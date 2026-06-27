<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class RtdcSeeder extends Seeder
{
    /**
     * Default config-driven unit mix: ED + 3 med/surg + ICU + step-down (~300 beds).
     */
    public function run(): void
    {
        $units = [
            ['name' => 'Emergency Department', 'abbreviation' => 'ED', 'type' => 'ed', 'staffed_bed_count' => 40, 'ratio_floor' => 4],
            ['name' => '5 East', 'abbreviation' => '5E', 'type' => 'med_surg', 'staffed_bed_count' => 32, 'ratio_floor' => 5],
            ['name' => '5 West', 'abbreviation' => '5W', 'type' => 'med_surg', 'staffed_bed_count' => 32, 'ratio_floor' => 5],
            ['name' => '6 East', 'abbreviation' => '6E', 'type' => 'med_surg', 'staffed_bed_count' => 32, 'ratio_floor' => 5],
            ['name' => 'ICU', 'abbreviation' => 'ICU', 'type' => 'icu', 'staffed_bed_count' => 20, 'ratio_floor' => 2],
            ['name' => 'Step-Down', 'abbreviation' => 'SD', 'type' => 'step_down', 'staffed_bed_count' => 24, 'ratio_floor' => 3],
        ];

        // Idempotent: firstOrCreate keyed on natural keys (unit abbreviation,
        // bed label per unit) so this is safe to run inside DatabaseSeeder on
        // every `php artisan db:seed` without accumulating duplicate units/beds.
        foreach ($units as $u) {
            $unit = Unit::firstOrCreate(['abbreviation' => $u['abbreviation']], $u);
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
    }
}
