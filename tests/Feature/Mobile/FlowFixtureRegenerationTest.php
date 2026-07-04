<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsFlowStory;
use Tests\TestCase;

/**
 * Regenerates the shared Flow contract fixtures by CAPTURING real BFF
 * responses (never hand-typed JSON):
 *
 *   FLOW_FIXTURE_DUMP=1 php artisan test --filter=FlowFixtureRegenerationTest
 *
 * Skipped in normal runs so fixtures stay stable for the iOS/Android decode
 * harnesses; regenerate whenever the window/floors payload shape changes.
 */
class FlowFixtureRegenerationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFlowStory;

    private const FIXTURE_DIR = 'docs/hummingbird/api-contract/fixtures';

    public function test_regenerate_flow_fixtures(): void
    {
        if (! env('FLOW_FIXTURE_DUMP')) {
            $this->markTestSkipped('Set FLOW_FIXTURE_DUMP=1 to regenerate the flow fixtures.');
        }

        $this->seed(RtdcSeeder::class);
        $this->seedFlowStory();

        // Give the plates asset real geometry (same CAD fixture the
        // navigator contract test uses) so decode harnesses see true shapes.
        $this->artisan('facility:import-catalog', [
            'path' => base_path('tests/Fixtures/facility/model_catalog_fixture.json'),
            '--facility-code' => 'ZEPHYRUS-500',
            '--facility-name' => 'Navigator Test Facility',
            '--source-name' => 'flow-fixture-catalog',
            '--map-operational' => true,
        ])->assertSuccessful();
        \Illuminate\Support\Facades\Storage::disk('local')
            ->delete(\App\Services\Flow\FloorPlateAssetService::ASSET_PATH);

        $user = User::factory()->create(['role' => 'bed_manager', 'must_change_password' => false, 'is_active' => true]);
        Sanctum::actingAs($user, ['mobile:read']);

        $window = $this->getJson('/api/mobile/v1/flow/window?persona=bed_manager')
            ->assertOk()
            ->json();
        $floors = $this->getJson('/api/mobile/v1/flow/floors')
            ->assertOk()
            ->json();

        // The turn map: an EVS tech at floor scope is the one lens/scope combo
        // that exercises bed_statuses (+ task-depth redaction) in a fixture.
        $evsUser = User::factory()->create(['role' => 'evs', 'must_change_password' => false, 'is_active' => true]);
        Sanctum::actingAs($evsUser, ['mobile:read']);
        $micuFloor = (int) app(\App\Support\Hospital\HospitalManifest::class)->unit('MICU')['floor'];
        $evsWindow = $this->getJson('/api/mobile/v1/flow/window?persona=evs&scope=floor:'.$micuFloor)
            ->assertOk()
            ->json();
        $this->assertArrayHasKey('bed_statuses', $evsWindow['data'], 'the EVS floor capture must include the turn map');

        $fixtures = [
            'mobile-flow-window.json' => $window,
            'mobile-flow-floors.json' => $floors,
            'mobile-flow-window-evs.json' => $evsWindow,
        ];
        foreach ($fixtures as $name => $payload) {
            file_put_contents(
                base_path(self::FIXTURE_DIR.'/'.$name),
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        $this->assertFileExists(base_path(self::FIXTURE_DIR.'/mobile-flow-window.json'));
    }
}
