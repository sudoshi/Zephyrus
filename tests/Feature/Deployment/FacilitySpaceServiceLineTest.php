<?php

namespace Tests\Feature\Deployment;

use App\Services\Deployment\ServiceLineNormalizer;
use App\Services\PatientFlow\FacilitySpaceLocationResolver;
use Database\Seeders\SummitDeploymentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 acceptance: Layer 4 many-to-many space <-> service line, code normalization,
 * facility_key backfill, and the hardened facility_spaces.service_line_code FK.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 2)
 */
class FacilitySpaceServiceLineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ServiceLineNormalizer::flush();
    }

    private function importFixture(string $facilityCode = 'ZEPHYRUS-500'): void
    {
        $exit = Artisan::call('facility:import-catalog', [
            'path' => base_path('tests/Fixtures/facility/model_catalog_fixture.json'),
            '--facility-code' => $facilityCode,
        ]);

        $this->assertSame(0, $exit, 'catalog import should succeed');
    }

    public function test_catalog_import_normalizes_codes_and_seeds_one_primary_bridge_row_per_space(): void
    {
        $this->importFixture();

        // Legacy 'trauma_surgery' was folded to canonical on write (never stored raw).
        $this->assertSame(
            0,
            DB::table('hosp_space.facility_spaces')->where('service_line_code', 'trauma_surgery')->count()
        );

        $spaceIds = DB::table('hosp_space.facility_spaces')
            ->where('service_line_code', 'trauma_acute_care_surgery')
            ->pluck('facility_space_id');
        $this->assertSame(3, $spaceIds->count(), 'the three trauma objects each became a facility space');

        // Exactly one primary bridge row per space, and it carries the canonical code.
        foreach ($spaceIds as $id) {
            $primaries = DB::table('hosp_space.facility_space_service_lines')
                ->where('facility_space_id', $id)
                ->where('primary_flag', true)
                ->pluck('service_line_code');

            $this->assertCount(1, $primaries);
            $this->assertSame('trauma_acute_care_surgery', $primaries->first());
        }

        // uq_fssl_one_primary invariant across every space.
        $spacesWithTwoPrimaries = DB::table('hosp_space.facility_space_service_lines')
            ->where('primary_flag', true)
            ->groupBy('facility_space_id')
            ->havingRaw('count(*) > 1')
            ->pluck('facility_space_id');
        $this->assertCount(0, $spacesWithTwoPrimaries);
    }

    public function test_service_line_fk_is_present_and_validated_with_zero_violations(): void
    {
        $this->importFixture();

        $constraint = DB::selectOne(
            "SELECT convalidated FROM pg_constraint
             WHERE conname = 'facility_spaces_service_line_fk'
               AND conrelid = 'hosp_space.facility_spaces'::regclass"
        );

        $this->assertNotNull($constraint, 'the service-line FK must exist');
        $this->assertTrue((bool) $constraint->convalidated, 'the FK must be VALIDATED (normalization ran)');

        // No facility space references a code missing from the registry.
        $orphans = DB::table('hosp_space.facility_spaces as s')
            ->whereNotNull('s.service_line_code')
            ->leftJoin('hosp_ref.service_lines as r', 's.service_line_code', '=', 'r.service_line_code')
            ->whereNull('r.service_line_code')
            ->count();
        $this->assertSame(0, $orphans);
    }

    public function test_shared_space_resolves_to_all_service_lines_and_keeps_back_compat_string(): void
    {
        Artisan::call('deployment:seed-registry');
        ServiceLineNormalizer::flush();

        // A shared CT scanner that five service lines all use.
        $spaceId = (int) DB::table('hosp_space.facility_spaces')->insertGetId([
            'space_code' => 'ZEPHYRUS-500:CT-01',
            'space_name' => 'CT Scanner 1',
            'space_category' => 'imaging',
            'service_line_code' => 'imaging_diagnostics',
            'location_role' => 'diagnostic',
            'status' => 'planned',
            'geometry' => json_encode(['source_object_code' => 'CT-01'], JSON_THROW_ON_ERROR),
            'attributes' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'facility_space_id');

        $lines = ['emergency', 'trauma_acute_care_surgery', 'neurosciences', 'oncology', 'imaging_diagnostics'];
        foreach ($lines as $code) {
            DB::insert(
                'INSERT INTO hosp_space.facility_space_service_lines
                    (facility_space_id, service_line_code, primary_flag, capability_tags, evidence, updated_at)
                 VALUES (?, ?, ?, ?::text[], ?::jsonb, now())',
                [$spaceId, $code, $code === 'imaging_diagnostics', '{}', '{}']
            );
        }

        $payload = app(FacilitySpaceLocationResolver::class)->resolve('CT-01', 'ZEPHYRUS-500');

        $this->assertNotNull($payload);
        $this->assertEqualsCanonicalizing($lines, $payload['service_lines']);
        $this->assertSame('imaging_diagnostics', $payload['service_lines'][0], 'primary sorts first');
        // Back-compat: the single service_line string is still emitted for the FE.
        $this->assertSame('imaging_diagnostics', $payload['service_line']);
        $this->assertSame('diagnostic', $payload['location_role']);
    }

    public function test_normalize_command_folds_legacy_codes_across_flow_stores(): void
    {
        Artisan::call('deployment:seed-registry');
        ServiceLineNormalizer::flush();

        $spaceId = (int) DB::table('hosp_space.facility_spaces')->insertGetId([
            'space_code' => 'ZEPHYRUS-500:OCC-01',
            'space_name' => 'Occupancy anchor',
            'space_category' => 'support',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'facility_space_id');

        // flow_events.service_line has no FK, so a legacy code can be stored then folded.
        DB::table('flow_core.patient_identities')->insert([
            'patient_ref' => 'PT-1',
            'patient_display_ref' => 'PT-1',
            'identifier_hash' => 'hash-1',
        ]);
        DB::table('flow_core.flow_events')->insert([
            'flow_event_id' => 'EV-1',
            'event_category' => 'movement',
            'event_type' => 'transfer',
            'patient_ref' => 'PT-1',
            'patient_display_ref' => 'PT-1',
            'occurred_at' => now(),
            'service_line' => 'cardiology',
        ]);

        DB::table('flow_core.occupancy_snapshots')->insert([
            'snapshot_at' => now(),
            'facility_space_id' => $spaceId,
            'active_patient_count' => 10,
            'service_line_counts' => json_encode([
                'cardiology' => 3,
                'cardiovascular' => 2,
                'trauma_surgery' => 1,
                'emergency' => 4,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('deployment:normalize-service-lines'));

        $this->assertSame(
            'cardiovascular',
            DB::table('flow_core.flow_events')->where('flow_event_id', 'EV-1')->value('service_line')
        );

        $counts = json_decode(
            DB::table('flow_core.occupancy_snapshots')->where('facility_space_id', $spaceId)->value('service_line_counts'),
            true
        );
        // cardiology(3) + cardiovascular(2) merge; trauma_surgery(1) folds; emergency(4) untouched.
        // jsonb does not preserve key order, so compare order-independently.
        $this->assertEqualsCanonicalizing(
            ['cardiovascular' => 5, 'trauma_acute_care_surgery' => 1, 'emergency' => 4],
            $counts
        );
    }

    public function test_backfill_sets_facility_key_from_cad_prefix_and_is_idempotent(): void
    {
        $this->seed(SummitDeploymentSeeder::class); // SUMMIT_REGIONAL.cad_facility_code = ZEPHYRUS-500
        ServiceLineNormalizer::flush();
        $this->importFixture('ZEPHYRUS-500');

        $this->assertSame(0, Artisan::call('deployment:backfill-space-service-lines'));

        // Every ZEPHYRUS-500-prefixed space now ties back to the flagship facility.
        $withoutKey = DB::table('hosp_space.facility_spaces')
            ->where('space_code', 'like', 'ZEPHYRUS-500:%')
            ->where(fn ($q) => $q->whereNull('facility_key')->orWhere('facility_key', '!=', 'SUMMIT_REGIONAL'))
            ->count();
        $this->assertSame(0, $withoutKey);

        $before = DB::table('hosp_space.facility_space_service_lines')->count();
        $this->assertGreaterThan(0, $before);

        // Re-running changes nothing.
        $this->assertSame(0, Artisan::call('deployment:backfill-space-service-lines'));
        $after = DB::table('hosp_space.facility_space_service_lines')->count();
        $this->assertSame($before, $after);
    }
}
