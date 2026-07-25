<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use Database\Seeders\RtdcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CanonicalizesMobileFixtureValues;
use Tests\Support\SeedsFlowStory;
use Tests\TestCase;

/**
 * Regenerates the shared Flow contract fixtures by CAPTURING real BFF
 * responses (never hand-typed JSON):
 *
 *   HUMMINGBIRD_FIXTURE_DUMP=1 php artisan test --filter=FlowFixtureRegenerationTest
 *
 * Normal runs compare checked-in fixtures to deterministic BFF responses without
 * writing. Regenerate only with the explicit environment flag whenever the
 * reviewed window/floors payload shape changes.
 */
class FlowFixtureRegenerationTest extends TestCase
{
    use CanonicalizesMobileFixtureValues;
    use RefreshDatabase;
    use SeedsFlowStory;

    private const FIXTURE_DIR = 'docs/hummingbird/api-contract/fixtures';

    public function test_flow_fixtures_match_deterministic_bff_contract(): void
    {
        // Stable capture time keeps the committed test artifact reviewable: a
        // real BFF payload should change only when its contract/source changes,
        // not simply because regeneration ran on a different day.
        Carbon::setTestNow('2026-07-24T03:05:15Z');

        try {
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
                ->assertOk();
            $floors = $this->getJson('/api/mobile/v1/flow/floors')
                ->assertOk();

            // The turn map: an EVS tech at floor scope is the one lens/scope combo
            // that exercises bed_statuses (+ task-depth redaction) in a fixture.
            $evsUser = User::factory()->create(['role' => 'evs', 'must_change_password' => false, 'is_active' => true]);
            Sanctum::actingAs($evsUser, ['mobile:read']);
            $micuFloor = (int) app(\App\Support\Hospital\HospitalManifest::class)->unit('MICU')['floor'];
            $evsWindow = $this->getJson('/api/mobile/v1/flow/window?persona=evs&scope=floor:'.$micuFloor)
                ->assertOk();
            $this->assertArrayHasKey('bed_statuses', $evsWindow->json('data'), 'the EVS floor capture must include the turn map');

            $fixtures = [
                'mobile-flow-window.json' => $window,
                'mobile-flow-floors.json' => $floors,
                'mobile-flow-window-evs.json' => $evsWindow,
            ];
            foreach ($fixtures as $name => $response) {
                $serialized = $this->formatFixture($response->getContent());
                if (env('HUMMINGBIRD_FIXTURE_DUMP') || env('FLOW_FIXTURE_DUMP')) {
                    file_put_contents(base_path(self::FIXTURE_DIR.'/'.$name), $serialized);
                } else {
                    $this->assertSame(
                        $this->canonicalizeMobileFixtureValue(json_decode(
                            file_get_contents(base_path(self::FIXTURE_DIR.'/'.$name)),
                            true,
                            flags: JSON_THROW_ON_ERROR,
                        )),
                        $this->canonicalizeMobileFixtureValue($response->json()),
                        "{$name} is stale. Review the deterministic BFF change, then run HUMMINGBIRD_FIXTURE_DUMP=1 to regenerate it.",
                    );
                }
            }

            $this->assertFileExists(base_path(self::FIXTURE_DIR.'/mobile-flow-window.json'));
        } finally {
            Carbon::setTestNow();
        }
    }

    private function formatFixture(string $responseBody): string
    {
        return json_encode(
            json_decode($responseBody, flags: JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
