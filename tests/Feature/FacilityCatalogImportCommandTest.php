<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FacilityCatalogImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_catalog_promotes_spaces_and_maps_units_and_beds(): void
    {
        $fixture = base_path('tests/Fixtures/facility/model_catalog_fixture.json');
        $facilityCode = 'TEST-FAC';

        $exitCode = Artisan::call('facility:import-catalog', [
            'path' => $fixture,
            '--facility-code' => $facilityCode,
            '--facility-name' => 'Test Facility',
            '--source-name' => 'fixture-catalog',
            '--map-operational' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('hosp_ingest.blueprint_imports', [
            'source_name' => 'fixture-catalog',
            'source_type' => 'catalog_json',
            'facility_code' => 'TEST-FAC',
            'status' => 'published',
        ]);

        $importId = (int) DB::table('hosp_ingest.blueprint_imports')
            ->where('source_name', 'fixture-catalog')
            ->where('facility_code', $facilityCode)
            ->value('blueprint_import_id');

        $this->assertSame(6, DB::table('hosp_ingest.blueprint_objects')
            ->where('blueprint_import_id', $importId)
            ->count());
        $this->assertSame(6, DB::table('hosp_space.facility_spaces')
            ->where('space_code', 'like', "{$facilityCode}:%")
            ->count());

        $this->assertDatabaseHas('hosp_ingest.blueprint_objects', [
            'object_code' => 'TICU-B001',
            'object_category' => 'bed',
            'review_status' => 'auto_accepted',
            'floor_label' => 'L3',
        ]);

        $this->assertDatabaseHas('hosp_space.facility_spaces', [
            'space_code' => 'TEST-FAC:TICU-B001',
            'space_category' => 'bed',
            'acuity_level' => 'icu',
        ]);

        $roomSpaceId = DB::table('hosp_space.facility_spaces')
            ->where('space_code', 'TEST-FAC:TICU-R001')
            ->value('facility_space_id');

        $this->assertDatabaseHas('hosp_space.facility_spaces', [
            'space_code' => 'TEST-FAC:TICU-B001',
            'parent_space_id' => $roomSpaceId,
        ]);

        $unit = DB::table('prod.units')->where('abbreviation', 'TICU')->first();
        $this->assertNotNull($unit);
        $this->assertSame('icu', $unit->type);
        $this->assertNotNull($unit->facility_space_id);

        $bed = DB::table('prod.beds')->where('label', 'TICU-B001')->first();
        $this->assertNotNull($bed);
        $this->assertSame($unit->unit_id, $bed->unit_id);
        $this->assertTrue((bool) $bed->isolation_capable);
        $this->assertNotNull($bed->facility_space_id);

        $this->assertSame(2, DB::table('hosp_space.operational_space_maps as maps')
            ->join('hosp_space.facility_spaces as spaces', 'spaces.facility_space_id', '=', 'maps.facility_space_id')
            ->where('spaces.space_code', 'like', "{$facilityCode}:%")
            ->count());
    }

    public function test_import_catalog_is_idempotent_for_same_source_checksum(): void
    {
        $fixture = base_path('tests/Fixtures/facility/model_catalog_fixture.json');
        $facilityCode = 'TEST-FAC';
        $arguments = [
            'path' => $fixture,
            '--facility-code' => $facilityCode,
            '--facility-name' => 'Test Facility',
            '--source-name' => 'fixture-catalog',
            '--map-operational' => true,
        ];

        $this->assertSame(0, Artisan::call('facility:import-catalog', $arguments));
        $this->assertSame(0, Artisan::call('facility:import-catalog', $arguments));

        $importId = (int) DB::table('hosp_ingest.blueprint_imports')
            ->where('source_name', 'fixture-catalog')
            ->where('facility_code', $facilityCode)
            ->value('blueprint_import_id');

        $this->assertSame(1, DB::table('hosp_ingest.blueprint_imports')
            ->where('source_name', 'fixture-catalog')
            ->where('facility_code', $facilityCode)
            ->count());
        $this->assertSame(6, DB::table('hosp_ingest.blueprint_objects')
            ->where('blueprint_import_id', $importId)
            ->count());
        $this->assertSame(6, DB::table('hosp_space.facility_spaces')
            ->where('space_code', 'like', "{$facilityCode}:%")
            ->count());
        $this->assertSame(1, DB::table('prod.units')->where('abbreviation', 'TICU')->count());
        $this->assertSame(1, DB::table('prod.beds')->where('label', 'TICU-B001')->count());
        $this->assertSame(2, DB::table('hosp_space.operational_space_maps as maps')
            ->join('hosp_space.facility_spaces as spaces', 'spaces.facility_space_id', '=', 'maps.facility_space_id')
            ->where('spaces.space_code', 'like', "{$facilityCode}:%")
            ->count());
    }
}
