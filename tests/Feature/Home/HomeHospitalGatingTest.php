<?php

namespace Tests\Feature\Home;

use App\Models\Home\HomeEpisode;
use App\Models\Home\HomeReferral;
use App\Models\Home\RpmKit;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\HomeHospitalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeHospitalGatingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_feature_flag_off_returns_404_and_hides_the_module(): void
    {
        config(['home_hospital.enabled' => false]);

        $this->actingAs($this->user)->get('/home/census')->assertNotFound();
        $this->actingAs($this->user)->getJson('/api/home/census')->assertNotFound();
    }

    public function test_flag_off_seeder_is_a_no_op(): void
    {
        config(['home_hospital.enabled' => false]);

        $this->seed(HomeHospitalDemoSeeder::class);

        $this->assertSame(0, Unit::where('type', 'virtual_home')->count());
        $this->assertSame(0, HomeEpisode::count());
    }

    public function test_census_page_renders_the_seeded_virtual_ward(): void
    {
        config(['home_hospital.enabled' => true]);
        $this->seed(HomeHospitalDemoSeeder::class);

        $this->actingAs($this->user)
            ->get('/home/census')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home/Census')
                ->where('unit.abbreviation', 'HOME')
                ->has('slots', 12)
                ->where('occupancy.occupied', 8)
                ->where('occupancy.capacity', 12)
                ->has('pipeline.counts')
                ->has('projectedDischarges.next24h')
            );
    }

    public function test_api_census_returns_the_same_payload(): void
    {
        config(['home_hospital.enabled' => true]);
        $this->seed(HomeHospitalDemoSeeder::class);

        $this->actingAs($this->user)
            ->getJson('/api/home/census')
            ->assertOk()
            ->assertJsonPath('unit.abbreviation', 'HOME')
            ->assertJsonPath('occupancy.occupied', 8)
            ->assertJsonPath('occupancy.capacity', 12)
            ->assertJsonPath('pipeline.counts.declined', 2);
    }

    public function test_census_payload_is_pseudonymous(): void
    {
        config(['home_hospital.enabled' => true]);
        $this->seed(HomeHospitalDemoSeeder::class);

        $payload = $this->actingAs($this->user)->getJson('/api/home/census')->json();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        // Operational payloads carry patient_ref + service zones only — never
        // an MRN or street address (build brief §4.2).
        $this->assertStringNotContainsString('mrn', strtolower($json));
        $this->assertStringNotContainsString('address', strtolower($json));
        $this->assertStringContainsString('HOME-DEMO-001', $json);
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        config(['home_hospital.enabled' => true]);

        $this->seed(HomeHospitalDemoSeeder::class);
        $first = [
            Unit::where('type', 'virtual_home')->count(),
            HomeEpisode::count(),
            HomeReferral::count(),
            RpmKit::count(),
        ];

        $this->seed(HomeHospitalDemoSeeder::class);
        $second = [
            Unit::where('type', 'virtual_home')->count(),
            HomeEpisode::count(),
            HomeReferral::count(),
            RpmKit::count(),
        ];

        $this->assertSame($first, $second);
        $this->assertSame([1, 8, 6, 10], $second);
    }
}
