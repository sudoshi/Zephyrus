<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Models\User;
use App\Services\Demo\Ancillary\AncillaryDemoScenarioService;
use App\Services\Demo\DemoClock;
use Carbon\CarbonImmutable;
use Database\Seeders\AncillaryReferenceSeeder;
use Database\Seeders\CaseManagementSeeder;
use Database\Seeders\CommandCenterDemoSeeder;
use Database\Seeders\RtdcSeeder;
use Database\Seeders\StaffingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * X-10 access control. The controlled-substance operational view is gated by the
 * dedicated viewControlledSubstanceOperations capability. An unauthorized user
 * must receive a clean denial on BOTH the page and the API, and the denial must
 * carry no controlled data and no existence/detail leak.
 */
final class PharmacyControlledAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $anchor = CarbonImmutable::parse('2026-07-11T14:00:00Z');
        CarbonImmutable::setTestNow($anchor);
        $this->seed([
            RtdcSeeder::class,
            CaseManagementSeeder::class,
            StaffingReferenceSeeder::class,
            CommandCenterDemoSeeder::class,
            AncillaryReferenceSeeder::class,
        ]);
        app(AncillaryDemoScenarioService::class)->refresh(new DemoClock($anchor));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function authorizedUser(): User
    {
        return User::factory()->create(['role' => 'pharmacy_manager']);
    }

    private function unauthorizedUser(): User
    {
        // A plain frontline account — never granted the controlled capability.
        return User::factory()->create(['role' => 'user']);
    }

    public function test_authorized_pharmacy_leadership_can_read_the_api(): void
    {
        $response = $this->actingAs($this->authorizedUser())
            ->getJson('/api/pharmacy/controlled')
            ->assertOk();

        $response->assertJsonPath('scope.diversionInvestigationInScope', false);
        $response->assertJsonStructure(['data' => ['summary', 'openDiscrepancies', 'stationPatterns', 'unitPatterns']]);
    }

    public function test_ops_leader_and_admin_are_authorized(): void
    {
        foreach (['ops_leader', 'admin', 'superuser', 'controlled_substance_officer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->getJson('/api/pharmacy/controlled')->assertOk();
        }
    }

    public function test_unauthorized_user_is_denied_on_the_api_without_leaking_controlled_data(): void
    {
        $response = $this->actingAs($this->unauthorizedUser())
            ->getJson('/api/pharmacy/controlled')
            ->assertForbidden();

        // The denial body carries NO controlled data — no discrepancy, station,
        // unit pattern, scope statement, or freshness envelope leaks.
        $body = strtolower((string) $response->getContent());
        foreach ([
            'discrepancy', 'openDiscrepancies', 'stationpatterns', 'unitpatterns',
            'overrideratepercent', 'shiftend', 'morphine', 'controlledvends',
        ] as $leak) {
            $this->assertStringNotContainsString(strtolower($leak), $body, "Denial body leaked controlled detail: {$leak}");
        }
        // No keys from the payload are present at all.
        $response->assertJsonMissingPath('data');
        $response->assertJsonMissingPath('scope');
    }

    public function test_unauthorized_user_is_denied_on_the_page(): void
    {
        $response = $this->actingAs($this->unauthorizedUser())
            ->get('/pharmacy/controlled')
            ->assertForbidden();

        $body = strtolower((string) $response->getContent());
        foreach (['discrepancy', 'morphine', 'shiftend', 'stationpatterns'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "Page denial leaked controlled detail: {$leak}");
        }
    }

    public function test_guest_is_redirected_or_denied_not_served(): void
    {
        // An unauthenticated request must never reach the controlled payload.
        $this->getJson('/api/pharmacy/controlled')->assertUnauthorized();
    }
}
