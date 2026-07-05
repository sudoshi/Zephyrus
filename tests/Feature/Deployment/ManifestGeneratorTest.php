<?php

namespace Tests\Feature\Deployment;

use App\Models\Unit;
use App\Services\Deployment\ManifestGenerator;
use App\Services\Deployment\ServiceLineNormalizer;
use App\Support\Hospital\HospitalManifest;
use Database\Seeders\GeisingerDeploymentSeeder;
use Database\Seeders\SummitDeploymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 5 acceptance: ManifestGenerator + hospital:generate-manifest reproduce the
 * Summit manifest's facility / service_lines / unit (abbr, cad_code, staffed_bed_count)
 * from the deployment tables (round-trip diff limited to canonicalized service-line
 * codes), a Geisinger spoke yields a smaller manifest with no Summit assumptions, and
 * HospitalManifest::forFacility('SUMMIT_REGIONAL') matches the container default.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 5)
 */
class ManifestGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Represent the completed Summit deployment pipeline: hosp_org geography +
     * capability matrix (SummitDeploymentSeeder) plus a canonical facility-space per
     * manifest unit, each linked to its prod.units row with a primary service-line
     * bridge row — exactly what a facility-space import + operational mapping produce.
     */
    private function seedSummitDeployment(): void
    {
        $this->seed(SummitDeploymentSeeder::class);

        $normalizer = app(ServiceLineNormalizer::class);
        $manifest = app(HospitalManifest::class);
        $now = Carbon::parse('2026-07-05 00:00:00');

        foreach ($manifest->units() as $u) {
            $canonical = $normalizer->canonical((string) $u['service_line']);

            $spaceId = DB::table('hosp_space.facility_spaces')->insertGetId([
                'space_code' => $u['cad_code'],
                'space_name' => $u['name'],
                'space_category' => 'unit',
                'floor_label' => (string) $u['floor'],
                'floor_number' => $u['floor'],
                'service_line_code' => $canonical,
                'acuity_level' => $u['acuity'],
                'facility_key' => 'SUMMIT_REGIONAL',
                'status' => 'active',
                'attributes' => json_encode([
                    'short_name' => $u['short_name'],
                    'nurse_ratio' => $u['nurse_ratio'],
                    'inpatient' => $u['inpatient'],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ], 'facility_space_id');

            Unit::create([
                'name' => $u['name'],
                'abbreviation' => $u['abbr'],
                'type' => $u['type'],
                'staffed_bed_count' => $u['staffed_bed_count'],
                'ratio_floor' => (int) round($u['nurse_ratio'] ?? 4),
                'facility_space_id' => $spaceId,
                'is_deleted' => false,
            ]);

            DB::table('hosp_space.facility_space_service_lines')->insert([
                'facility_space_id' => $spaceId,
                'service_line_code' => $canonical,
                'primary_flag' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function test_generates_summit_facility_block_matching_the_reference_manifest(): void
    {
        $this->seedSummitDeployment();

        $generated = app(ManifestGenerator::class)->generate('SUMMIT_REGIONAL');
        $expected = app(HospitalManifest::class)->facility();

        // The facility block round-trips exactly (code/city/type/tagline via hosp_org metadata).
        $this->assertSame($expected, $generated['facility']);
    }

    public function test_generated_service_lines_match_the_manifest_canonicalized(): void
    {
        $this->seedSummitDeployment();

        $normalizer = app(ServiceLineNormalizer::class);
        $generated = app(ManifestGenerator::class)->generate('SUMMIT_REGIONAL');

        $generatedCodes = array_map(fn (array $l): string => $l['code'], $generated['service_lines']);

        $expectedCodes = array_values(array_unique(array_map(
            fn (array $l): string => $normalizer->canonical((string) $l['code']),
            app(HospitalManifest::class)->serviceLines()
        )));

        // The only round-trip diff is canonicalization: cardiology folds into cardiovascular.
        $this->assertEqualsCanonicalizing($expectedCodes, $generatedCodes);
        $this->assertContains('cardiovascular', $generatedCodes);
        $this->assertNotContains('cardiology', $generatedCodes);
        $this->assertContains('trauma_acute_care_surgery', $generatedCodes);
        $this->assertNotContains('trauma_surgery', $generatedCodes);
        $this->assertContains('hospital_medicine', $generatedCodes);
        $this->assertNotContains('medicine', $generatedCodes);

        // Every generated line carries a display name from the registry.
        foreach ($generated['service_lines'] as $line) {
            $this->assertNotEmpty($line['name']);
        }
    }

    public function test_generated_units_reproduce_abbr_cad_code_and_staffed_bed_count(): void
    {
        $this->seedSummitDeployment();

        $generated = app(ManifestGenerator::class)->generate('SUMMIT_REGIONAL');
        $manifestUnits = app(HospitalManifest::class)->units();

        $this->assertCount(count($manifestUnits), $generated['units']);

        $byAbbr = [];
        foreach ($generated['units'] as $unit) {
            $byAbbr[$unit['abbr']] = $unit;
        }

        foreach ($manifestUnits as $u) {
            $this->assertArrayHasKey($u['abbr'], $byAbbr, "unit {$u['abbr']} missing from generated manifest");
            $this->assertSame($u['cad_code'], $byAbbr[$u['abbr']]['cad_code'], "cad_code mismatch for {$u['abbr']}");
            $this->assertSame($u['staffed_bed_count'], $byAbbr[$u['abbr']]['staffed_bed_count'], "staffed_bed_count mismatch for {$u['abbr']}");
        }

        // Canonicalized service line rides along on the reconstructed unit (7E cardiology -> cardiovascular).
        $this->assertSame('cardiovascular', $byAbbr['7E']['service_line']);
        $this->assertSame('hospital_medicine', $byAbbr['7W']['service_line']);
        $this->assertFalse($byAbbr['ED']['inpatient']);
        $this->assertTrue($byAbbr['MICU']['inpatient']);
    }

    public function test_spoke_manifest_is_smaller_and_leaks_no_summit_assumptions(): void
    {
        $this->seed(GeisingerDeploymentSeeder::class);

        $generated = app(ManifestGenerator::class)->generate('GEISINGER_LEWISTOWN');
        $codes = array_map(fn (array $l): string => $l['code'], $generated['service_lines']);

        // A community spoke runs emergency + (stabilize) trauma — and nothing quaternary.
        $this->assertContains('emergency', $codes);
        $this->assertContains('trauma_acute_care_surgery', $codes);
        $this->assertNotContains('transplant', $codes);
        $this->assertNotContains('neonatology', $codes);
        $this->assertLessThan(5, count($codes), 'a spoke manifest must be far smaller than Summit');

        // No prod units / facility spaces exist for the spoke → no Summit unit roster leaks in.
        $this->assertSame([], $generated['units']);
        $this->assertSame([], $generated['census_demo_targets']);

        // The facility block is the spoke's own identity, not Summit's.
        $this->assertSame('Geisinger Lewistown', $generated['facility']['name']);
        $this->assertNotSame('ZEPHYRUS-500', $generated['facility']['cad_facility_code']);
    }

    public function test_network_facilities_lists_the_idn_flagship_first(): void
    {
        $this->seedSummitDeployment();

        $generated = app(ManifestGenerator::class)->generate('SUMMIT_REGIONAL');
        $network = $generated['network_facilities'];

        $this->assertNotEmpty($network);
        $this->assertSame('flagship', $network[0]['role']);
        $this->assertSame('HOSP1', $network[0]['code']);

        $codes = array_map(fn (array $f): string => $f['code'], $network);
        $this->assertContains('HAWTH', $codes);
        $this->assertContains('CASTG', $codes);
    }

    public function test_for_facility_default_matches_the_container_manifest(): void
    {
        $default = app(HospitalManifest::class);
        $explicit = HospitalManifest::forFacility('SUMMIT_REGIONAL');

        $this->assertSame('SUMMIT_REGIONAL', $explicit->facilityKey());
        $this->assertSame($default->facility(), $explicit->facility());
        $this->assertSame($default->units(), $explicit->units());
        $this->assertSame($default->serviceLines(), $explicit->serviceLines());
    }

    public function test_for_facility_unknown_key_throws(): void
    {
        $this->expectException(RuntimeException::class);

        HospitalManifest::forFacility('NOT_A_FACILITY')->facility();
    }

    public function test_command_writes_a_valid_php_manifest_that_round_trips(): void
    {
        $this->seedSummitDeployment();

        $path = tempnam(sys_get_temp_dir(), 'manifest').'.php';

        try {
            $exit = Artisan::call('hospital:generate-manifest', [
                'facilityKey' => 'SUMMIT_REGIONAL',
                '--write' => $path,
            ]);

            $this->assertSame(0, $exit);

            $loaded = require $path;

            $this->assertIsArray($loaded);
            $this->assertSame('HOSP1', $loaded['facility']['code']);
            $this->assertCount(count(app(HospitalManifest::class)->units()), $loaded['units']);
            $this->assertNotEmpty($loaded['service_lines']);
        } finally {
            @unlink($path);
        }
    }

    public function test_command_stdout_path_runs_and_unknown_facility_fails(): void
    {
        $this->seedSummitDeployment();

        // The --write=/dev/stdout branch renders + streams the same PHP the file-write
        // round-trip test already verifies; here we assert the code path exits cleanly.
        $ok = Artisan::call('hospital:generate-manifest', [
            'facilityKey' => 'SUMMIT_REGIONAL',
            '--write' => '/dev/stdout',
        ]);
        $this->assertSame(0, $ok);

        // An unknown facility_key fails loudly rather than emitting an empty manifest.
        $fail = Artisan::call('hospital:generate-manifest', ['facilityKey' => 'NOT_A_FACILITY']);
        $this->assertSame(1, $fail);
    }
}
