<?php

namespace Tests\Feature\Deployment;

use App\Casts\PgTextArray;
use App\Services\Deployment\CapabilityTagBackfiller;
use App\Support\Hospital\HospitalManifest;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 acceptance: default capability tags land on prod.beds from unit acuity, the
 * heuristic is registered/bed-applicable, and deployment:audit-tags flags orphans.
 *
 * Plan: docs/superpowers/plans/2026-07-04-service-line-location-deployment-implementation.md (Phase 3)
 */
class CapabilityTagTest extends TestCase
{
    use RefreshDatabase;

    private function bedTags(string $unitAbbr): array
    {
        return DB::table('prod.beds as b')
            ->join('prod.units as u', 'u.unit_id', '=', 'b.unit_id')
            ->where('u.abbreviation', $unitAbbr)
            ->where('b.is_deleted', false)
            ->selectRaw("array_to_string(b.capability_tags, ',') as tags")
            ->pluck('tags')
            ->all();
    }

    public function test_default_bed_tags_heuristic_maps_acuity_and_service_line(): void
    {
        $backfiller = app(CapabilityTagBackfiller::class);

        $this->assertEqualsCanonicalizing(['ventilator', 'telemetry', 'medical_gas'], $backfiller->defaultBedTags('icu'));
        $this->assertEqualsCanonicalizing(['ventilator', 'telemetry', 'medical_gas', 'burn'], $backfiller->defaultBedTags('burn_icu'));
        $this->assertEqualsCanonicalizing(['telemetry', 'medical_gas'], $backfiller->defaultBedTags('telemetry'));
        $this->assertSame(['behavioral_safe'], $backfiller->defaultBedTags('behavioral'));
        $this->assertSame(['medical_gas'], $backfiller->defaultBedTags('med_surg'));

        // Neonatology takes precedence over the 'pediatrics' acuity a NICU carries.
        $this->assertEqualsCanonicalizing(['neonatal', 'medical_gas'], $backfiller->defaultBedTags('pediatrics', 'neonatology'));
        // A regular peds floor stays pediatric.
        $this->assertEqualsCanonicalizing(['pediatric', 'medical_gas'], $backfiller->defaultBedTags('pediatrics', 'pediatrics'));
        // Legacy service-line codes are folded before matching.
        $this->assertEqualsCanonicalizing(['ventilator', 'telemetry', 'medical_gas'], $backfiller->defaultBedTags('icu', 'trauma_surgery'));
    }

    public function test_rtdc_seeder_tags_icu_and_behavioral_beds(): void
    {
        $this->seed(RtdcSeeder::class);

        // Every ICU bed (MICU) carries ventilator + telemetry.
        foreach ($this->bedTags('MICU') as $tags) {
            $this->assertStringContainsString('ventilator', $tags);
            $this->assertStringContainsString('telemetry', $tags);
        }

        // Every behavioral bed (BHU) carries behavioral_safe.
        $bhu = $this->bedTags('BHU');
        $this->assertNotEmpty($bhu);
        foreach ($bhu as $tags) {
            $this->assertStringContainsString('behavioral_safe', $tags);
        }
    }

    public function test_ventilator_bed_count_matches_icu_family_from_manifest(): void
    {
        $this->seed(RtdcSeeder::class);

        $manifest = app(HospitalManifest::class);
        $backfiller = app(CapabilityTagBackfiller::class);

        $expected = 0;
        foreach ($manifest->units() as $u) {
            $tags = $backfiller->defaultBedTags($u['acuity'] ?? null, $u['service_line'] ?? null, $u['type'] ?? null);
            if (in_array('ventilator', $tags, true)) {
                $expected += (int) $u['staffed_bed_count'];
            }
        }
        $this->assertGreaterThan(0, $expected);

        $actual = DB::table('prod.beds')
            ->where('is_deleted', false)
            ->whereRaw("'ventilator' = ANY(capability_tags)")
            ->count();

        $this->assertSame($expected, $actual);
    }

    public function test_backfill_is_non_destructive_to_existing_tags(): void
    {
        $unitId = (int) DB::table('prod.units')->insertGetId([
            'name' => 'Flex ICU', 'abbreviation' => 'FLEX', 'type' => 'icu',
            'staffed_bed_count' => 2, 'ratio_floor' => 2,
            'created_at' => now(), 'updated_at' => now(), 'is_deleted' => false,
        ], 'unit_id');

        $customBed = (int) DB::table('prod.beds')->insertGetId([
            'unit_id' => $unitId, 'label' => 'FLEX-01', 'status' => 'available',
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'bed_id');
        $emptyBed = (int) DB::table('prod.beds')->insertGetId([
            'unit_id' => $unitId, 'label' => 'FLEX-02', 'status' => 'available',
            'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
        ], 'bed_id');

        // A client-customized bed.
        DB::update("UPDATE prod.beds SET capability_tags = '{mri}'::text[] WHERE bed_id = ?", [$customBed]);

        $updated = app(CapabilityTagBackfiller::class)->applyToUnitBeds($unitId, ['ventilator', 'telemetry']);
        $this->assertSame(1, $updated, 'only the empty bed is tagged');

        // The customized bed is untouched; the empty bed gets the defaults.
        $this->assertSame(['mri'], PgTextArray::parse(DB::table('prod.beds')->where('bed_id', $customBed)->value('capability_tags')));
        $this->assertEqualsCanonicalizing(
            ['ventilator', 'telemetry'],
            PgTextArray::parse(DB::table('prod.beds')->where('bed_id', $emptyBed)->value('capability_tags'))
        );

        // Re-running changes nothing.
        $this->assertSame(0, app(CapabilityTagBackfiller::class)->applyToUnitBeds($unitId, ['ventilator', 'telemetry']));
    }

    public function test_audit_tags_passes_clean_and_flags_orphans(): void
    {
        Artisan::call('deployment:seed-registry');
        $this->seed(RtdcSeeder::class);

        // Clean Summit: zero orphans, exit success.
        $this->assertSame(0, Artisan::call('deployment:audit-tags'));

        $orphanQuery = DB::table('prod.beds')
            ->whereRaw('cardinality(capability_tags) > 0')
            ->distinct()
            ->selectRaw('unnest(capability_tags) as tag')
            ->pluck('tag')
            ->reject(fn ($tag): bool => DB::table('hosp_ref.capability_tags')->where('tag_code', $tag)->exists());
        $this->assertCount(0, $orphanQuery, 'no bed tag is missing from the registry');

        // Inject an unregistered tag -> audit fails.
        $bedId = DB::table('prod.beds')->where('is_deleted', false)->value('bed_id');
        DB::update("UPDATE prod.beds SET capability_tags = capability_tags || '{not_a_real_tag}'::text[] WHERE bed_id = ?", [$bedId]);

        $this->assertSame(1, Artisan::call('deployment:audit-tags'));
    }

    public function test_catalog_import_map_operational_tags_icu_bed(): void
    {
        $exit = Artisan::call('facility:import-catalog', [
            'path' => base_path('tests/Fixtures/facility/model_catalog_fixture.json'),
            '--facility-code' => 'TAG-FAC',
            '--map-operational' => true,
        ]);
        $this->assertSame(0, $exit);

        $tags = PgTextArray::parse(
            DB::table('prod.beds')->where('label', 'TICU-B001')->value('capability_tags')
        );
        $this->assertContains('ventilator', $tags);
        $this->assertContains('telemetry', $tags);
        $this->assertContains('medical_gas', $tags);
    }
}
