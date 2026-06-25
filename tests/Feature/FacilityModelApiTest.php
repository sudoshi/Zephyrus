<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class FacilityModelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_facility_model_summary_returns_latest_import_counts(): void
    {
        $facilityCode = 'API-FAC-'.Str::upper(Str::random(6));
        $fixture = base_path('tests/Fixtures/facility/model_catalog_fixture.json');

        $this->assertSame(0, Artisan::call('facility:import-catalog', [
            'path' => $fixture,
            '--facility-code' => $facilityCode,
            '--facility-name' => 'API Test Facility',
            '--source-name' => 'api-fixture-catalog',
            '--map-operational' => true,
        ]));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/facility/model/summary?facility_code='.$facilityCode)
            ->assertOk()
            ->assertJsonPath('data.facility_code', $facilityCode)
            ->assertJsonPath('data.latest_import.source_name', 'api-fixture-catalog')
            ->assertJsonPath('data.latest_import.facility_name', 'API Test Facility')
            ->assertJsonPath('data.imports.total', 1)
            ->assertJsonPath('data.imports.by_status.published', 1)
            ->assertJsonPath('data.blueprint_objects.total', 6)
            ->assertJsonPath('data.blueprint_objects.by_category.bed', 1)
            ->assertJsonPath('data.blueprint_objects.by_category.patient_room', 1)
            ->assertJsonPath('data.facility_spaces.total', 6)
            ->assertJsonPath('data.facility_spaces.by_category.bed', 1)
            ->assertJsonPath('data.facility_spaces.by_category.unit', 1)
            ->assertJsonPath('data.operational_mappings.total_active', 2)
            ->assertJsonPath('data.operational_mappings.units', 1)
            ->assertJsonPath('data.operational_mappings.beds', 1)
            ->assertJsonPath('data.operational_mappings.unmapped_spaces', 4)
            ->assertJsonPath('data.prod_links.units', 1)
            ->assertJsonPath('data.prod_links.beds', 1);
    }

    public function test_facility_model_summary_requires_authentication(): void
    {
        $this->getJson('/api/facility/model/summary')->assertUnauthorized();
    }
}
