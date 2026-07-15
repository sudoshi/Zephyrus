<?php

declare(strict_types=1);

namespace Tests\Feature\Ancillary;

use App\Http\Controllers\Analytics\PharmacyTatController;
use App\Http\Controllers\Api\Pharmacy\PharmacyControlledController;
use App\Http\Controllers\Api\Pharmacy\PharmacyDischargeReadinessController;
use App\Http\Controllers\Api\Pharmacy\PharmacyDispenseController;
use App\Http\Controllers\Api\Pharmacy\PharmacyFlowBoardController;
use App\Http\Controllers\Api\Pharmacy\PharmacyIvRoomController;
use App\Http\Controllers\PharmacyController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * X-13 route + capability ownership for the Pharmacy Workspace domain (the 8th
 * official Workspace). Proves the five operational bookmarks and the Study leaf
 * keep stable names/actions/middleware with a single GET owner, that the read
 * APIs and the single barrier command are named and throttled, that every read
 * rejects anonymous access, and that the three ancillary capabilities
 * (patient-detail, barrier-mutation, controlled-substance operations) remain
 * independent and role-scoped.
 */
final class PharmacyRouteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_and_study_pages_keep_canonical_bookmarks_actions_and_authentication(): void
    {
        $expected = [
            'pharmacy.flow-board' => ['pharmacy', PharmacyController::class.'@index'],
            'pharmacy.discharge-meds' => ['pharmacy/discharge-meds', PharmacyController::class.'@dischargeMeds'],
            'pharmacy.iv-room' => ['pharmacy/iv-room', PharmacyController::class.'@ivRoom'],
            'pharmacy.dispense' => ['pharmacy/dispense', PharmacyController::class.'@dispense'],
            'pharmacy.controlled' => ['pharmacy/controlled', PharmacyController::class.'@controlled'],
            'analytics.pharmacy-tat' => ['analytics/pharmacy-tat', PharmacyTatController::class],
        ];

        foreach ($expected as $name => [$uri, $action]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing {$name}.");
            $this->assertSame($uri, $route->uri(), $name);
            $this->assertContains('GET', $route->methods(), $name);
            $this->assertSame($action, $route->getActionName(), $name);
            $this->assertContains('App\\Http\\Middleware\\SessionAuthMiddleware', $route->gatherMiddleware(), $name);
            $this->assertSame(1, collect(app('router')->getRoutes())->filter(
                fn (Route $candidate): bool => $candidate->uri() === $uri && in_array('GET', $candidate->methods(), true),
            )->count(), "{$uri} must have one GET owner.");
        }
    }

    public function test_pharmacy_routes_are_registered_after_the_lab_group_and_before_rtdc(): void
    {
        $webRoutes = file_get_contents(base_path('routes/web.php'));
        $labOffset = strpos($webRoutes, "Route::prefix('lab')->name('lab.')");
        $pharmacyOffset = strpos($webRoutes, "Route::prefix('pharmacy')->name('pharmacy.')");
        $rtdcOffset = strpos($webRoutes, "Route::prefix('rtdc')->name('rtdc.')");

        $this->assertIsInt($labOffset, 'The Lab route group is missing from routes/web.php.');
        $this->assertIsInt($pharmacyOffset, 'The Pharmacy route group is missing from routes/web.php.');
        $this->assertIsInt($rtdcOffset, 'The RTDC route group is missing from routes/web.php.');
        $this->assertLessThan($pharmacyOffset, $labOffset, 'Pharmacy routes must follow the Lab group.');
        $this->assertLessThan($rtdcOffset, $pharmacyOffset, 'Pharmacy routes must remain registered before RTDC routes.');
    }

    public function test_read_apis_and_barrier_mutation_have_stable_named_ownership(): void
    {
        $expected = [
            'api.pharmacy.flow-board' => ['api/pharmacy/flow-board', PharmacyFlowBoardController::class.'@show', 'GET'],
            'api.pharmacy.discharge-readiness' => ['api/pharmacy/discharge-readiness', PharmacyDischargeReadinessController::class.'@show', 'GET'],
            'api.pharmacy.iv-room' => ['api/pharmacy/iv-room', PharmacyIvRoomController::class.'@show', 'GET'],
            'api.pharmacy.dispense' => ['api/pharmacy/dispense', PharmacyDispenseController::class.'@show', 'GET'],
            'api.pharmacy.controlled' => ['api/pharmacy/controlled', PharmacyControlledController::class.'@show', 'GET'],
            'api.pharmacy.tat' => ['api/pharmacy/tat', PharmacyFlowBoardController::class.'@tat', 'GET'],
            'api.pharmacy.barriers.store' => ['api/pharmacy/barriers', PharmacyFlowBoardController::class.'@storeBarrier', 'POST'],
        ];

        foreach ($expected as $name => [$uri, $action, $verb]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing {$name}.");
            $this->assertSame($uri, $route->uri(), $name);
            $this->assertContains($verb, $route->methods(), $name);
            $this->assertSame($action, $route->getActionName(), $name);
            $this->assertContains('auth', $route->gatherMiddleware(), $name);
            $this->assertContains('throttle:60,1', $route->gatherMiddleware(), $name);
            $this->assertSame(1, collect(app('router')->getRoutes())->filter(
                fn (Route $candidate): bool => $candidate->uri() === $uri && in_array($verb, $candidate->methods(), true),
            )->count(), "{$verb} {$uri} must have one owner.");
        }
    }

    public function test_every_read_api_requires_authentication_and_non_read_methods_are_not_registered(): void
    {
        foreach (['flow-board', 'discharge-readiness', 'iv-room', 'dispense', 'controlled', 'tat'] as $endpoint) {
            $this->getJson('/api/pharmacy/'.$endpoint)->assertUnauthorized();
            $this->postJson('/api/pharmacy/'.$endpoint)->assertMethodNotAllowed();
        }

        $this->postJson('/api/pharmacy/barriers', [])->assertUnauthorized();
        $this->getJson('/api/pharmacy/barriers')->assertMethodNotAllowed();
    }

    public function test_no_result_dispense_or_controlled_mutation_route_is_registered(): void
    {
        // The Zephyrus-owned write surface is EXACTLY one barrier command. No
        // dispense, controlled, IV, or Study mutation route may exist — writeback
        // to any source system is forbidden.
        $forbidden = [
            'api/pharmacy/dispense',
            'api/pharmacy/controlled',
            'api/pharmacy/iv-room',
            'api/pharmacy/flow-board',
            'api/pharmacy/tat',
        ];

        foreach ($forbidden as $uri) {
            $writeVerbs = collect(app('router')->getRoutes())->filter(
                fn (Route $candidate): bool => $candidate->uri() === $uri
                    && count(array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $candidate->methods())) > 0,
            );
            $this->assertCount(0, $writeVerbs, "{$uri} must not register a write verb.");
        }
    }

    public function test_ancillary_capabilities_are_independent_and_role_scoped(): void
    {
        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $pharmacyManager = User::factory()->create(['role' => 'pharmacy_manager', 'must_change_password' => false]);
        $operationsManager = User::factory()->create(['role' => 'bed-manager', 'must_change_password' => false]);
        $opsLeader = User::factory()->create(['role' => 'ops_leader', 'must_change_password' => false]);

        // Frontline holds none of the three ancillary capabilities.
        $this->assertFalse(Gate::forUser($frontline)->allows('viewAncillaryPatientDetail'));
        $this->assertFalse(Gate::forUser($frontline)->allows('manageAncillaryBarriers'));
        $this->assertFalse(Gate::forUser($frontline)->allows('viewControlledSubstanceOperations'));

        // Pharmacy leadership sees controlled operations but NOT (by role alone)
        // barrier mutation or pseudonymous patient detail — the capabilities are
        // orthogonal, not a hierarchy.
        $this->assertTrue(Gate::forUser($pharmacyManager)->allows('viewControlledSubstanceOperations'));
        $this->assertFalse(Gate::forUser($pharmacyManager)->allows('manageAncillaryBarriers'));
        $this->assertFalse(Gate::forUser($pharmacyManager)->allows('viewAncillaryPatientDetail'));

        // A bed manager sees patient detail and may annotate barriers, but is not
        // granted the deployment-governed controlled surface.
        $this->assertTrue(Gate::forUser($operationsManager)->allows('viewAncillaryPatientDetail'));
        $this->assertTrue(Gate::forUser($operationsManager)->allows('manageAncillaryBarriers'));
        $this->assertFalse(Gate::forUser($operationsManager)->allows('viewControlledSubstanceOperations'));

        // Ops leadership spans all three.
        $this->assertTrue(Gate::forUser($opsLeader)->allows('viewAncillaryPatientDetail'));
        $this->assertTrue(Gate::forUser($opsLeader)->allows('manageAncillaryBarriers'));
        $this->assertTrue(Gate::forUser($opsLeader)->allows('viewControlledSubstanceOperations'));
    }
}
