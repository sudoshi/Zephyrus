<?php

namespace Tests\Feature\Rtdc;

use App\Models\User;
use App\Services\Rtdc\AncillaryServicesService;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AncillaryServicesRadiologyDrillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RtdcSeeder::class);
    }

    public function test_imaging_and_lab_tiles_carry_unit_scoped_owned_workspace_drills_only(): void
    {
        $payload = app(AncillaryServicesService::class)->build();

        $this->assertNotEmpty($payload);
        foreach ($payload as $unit) {
            foreach (['stress', 'echo', 'ct_mri', 'diagnostic', 'ir'] as $serviceId) {
                if ($unit['services'][$serviceId] === null) {
                    continue;
                }

                $this->assertSame(
                    "/radiology/worklist?unitId={$unit['id']}&source=ancillary_services",
                    $unit['services'][$serviceId]['drillHref'],
                );
            }

            if ($unit['services']['lab'] !== null) {
                $this->assertSame(
                    "/lab?unitId={$unit['id']}&source=ancillary_services",
                    $unit['services']['lab']['drillHref'],
                );
            }

            foreach (['pt_ot', 'respiratory', 'dialysis', 'pharmacy'] as $serviceId) {
                if ($unit['services'][$serviceId] !== null) {
                    $this->assertNull($unit['services'][$serviceId]['drillHref']);
                }
            }
        }
    }

    public function test_rtdc_page_preserves_the_server_owned_drill_contract(): void
    {
        $user = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false]);
        $expected = app(AncillaryServicesService::class)->build();

        $this->actingAs($user)->get('/rtdc/ancillary-services')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('RTDC/AncillaryServices')
                ->where('unitServices', $expected));
    }
}
