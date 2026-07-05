<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FacilityModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprint_ingestion_tables_exist_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('hosp_ingest.blueprint_imports'));
        $this->assertTrue(Schema::hasColumns('hosp_ingest.blueprint_imports', [
            'blueprint_import_id',
            'source_name',
            'source_type',
            'source_checksum',
            'facility_code',
            'facility_name',
            'coordinate_units',
            'status',
            'metadata',
        ]));

        $this->assertTrue(Schema::hasTable('hosp_ingest.blueprint_objects'));
        $this->assertTrue(Schema::hasColumns('hosp_ingest.blueprint_objects', [
            'blueprint_object_id',
            'blueprint_import_id',
            'object_code',
            'object_category',
            'floor_number',
            'position_ft',
            'size_ft',
            'bounds_ft',
            'metadata',
            'classification',
            'review_status',
        ]));
    }

    public function test_facility_space_tables_and_prod_links_exist(): void
    {
        $this->assertTrue(Schema::hasTable('hosp_ref.facility_object_categories'));
        $this->assertTrue(Schema::hasTable('hosp_space.facility_spaces'));
        $this->assertTrue(Schema::hasTable('hosp_space.operational_space_maps'));

        $this->assertTrue(Schema::hasColumns('hosp_space.facility_spaces', [
            'facility_space_id',
            'blueprint_object_id',
            'parent_space_id',
            'space_code',
            'space_name',
            'space_category',
            'floor_number',
            'geometry',
            'attributes',
            'source_confidence',
        ]));

        $this->assertTrue(Schema::hasColumn('prod.locations', 'facility_space_id'));
        $this->assertTrue(Schema::hasColumn('prod.rooms', 'facility_space_id'));
        $this->assertTrue(Schema::hasColumn('prod.units', 'facility_space_id'));
        $this->assertTrue(Schema::hasColumn('prod.beds', 'facility_space_id'));
    }

    public function test_category_catalog_matches_generated_cad_model_classes(): void
    {
        $expected = [
            'floor',
            'corridor',
            'care_unit',
            'patient_room',
            'bed',
            'emergency_department',
            'procedure_room',
            'procedure_support',
            'imaging',
            'elevator',
            'helipad',
            'support_infrastructure',
        ];

        $actual = DB::table('hosp_ref.facility_object_categories')
            ->whereIn('category_code', $expected)
            ->pluck('category_code')
            ->all();

        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_facility_space_can_map_to_existing_rtdc_unit(): void
    {
        // facility_spaces.service_line_code carries a validated FK onto the registry
        // (2026_07_04_000160), so the vocabulary must exist before inserting a space.
        Artisan::call('deployment:seed-registry');

        $importId = (int) DB::table('hosp_ingest.blueprint_imports')->insertGetId([
            'source_name' => 'Unit test model catalog',
            'source_type' => 'catalog_json',
            'facility_code' => 'TEST',
            'facility_name' => 'Test Hospital',
            'status' => 'parsed',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'blueprint_import_id');

        $objectId = (int) DB::table('hosp_ingest.blueprint_objects')->insertGetId([
            'blueprint_import_id' => $importId,
            'object_code' => 'UNIT-TEST-ICU',
            'object_name' => 'Test ICU',
            'object_category' => 'care_unit',
            'floor_number' => 3,
            'position_ft' => json_encode(['x' => 0, 'level' => 60, 'z' => 0], JSON_THROW_ON_ERROR),
            'size_ft' => json_encode(['x' => 128, 'y' => 2.5, 'z' => 104], JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['planned_beds' => 24], JSON_THROW_ON_ERROR),
            'classification' => json_encode(['source' => 'test'], JSON_THROW_ON_ERROR),
            'review_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'blueprint_object_id');

        $facilitySpaceId = (int) DB::table('hosp_space.facility_spaces')->insertGetId([
            'blueprint_object_id' => $objectId,
            'space_code' => 'TEST-ICU',
            'space_name' => 'Test ICU',
            'space_category' => 'unit',
            'floor_number' => 3,
            'service_line_code' => 'critical_care',
            'acuity_level' => 'icu',
            'status' => 'planned',
            'geometry' => json_encode(['source_object_code' => 'UNIT-TEST-ICU'], JSON_THROW_ON_ERROR),
            'attributes' => json_encode(['planned_beds' => 24], JSON_THROW_ON_ERROR),
            'source_confidence' => 0.95,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'facility_space_id');

        $unitId = (int) DB::table('prod.units')->insertGetId([
            'name' => 'Test ICU',
            'abbreviation' => 'TICU',
            'type' => 'icu',
            'staffed_bed_count' => 24,
            'ratio_floor' => 2,
            'access_standard_minutes' => 60,
            'facility_space_id' => $facilitySpaceId,
            'created_at' => now(),
            'updated_at' => now(),
            'is_deleted' => false,
        ], 'unit_id');

        DB::table('hosp_space.operational_space_maps')->insert([
            'facility_space_id' => $facilitySpaceId,
            'unit_id' => $unitId,
            'mapping_type' => 'canonical',
            'mapping_confidence' => 0.95,
            'evidence' => json_encode(['object_code' => 'UNIT-TEST-ICU'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('prod.units', [
            'unit_id' => $unitId,
            'facility_space_id' => $facilitySpaceId,
        ]);

        $this->assertDatabaseHas('hosp_space.operational_space_maps', [
            'facility_space_id' => $facilitySpaceId,
            'unit_id' => $unitId,
            'mapping_type' => 'canonical',
        ]);
    }
}
