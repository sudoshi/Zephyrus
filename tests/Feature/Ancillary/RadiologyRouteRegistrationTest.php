<?php

namespace Tests\Feature\Ancillary;

use App\Http\Controllers\Analytics\IrSuiteController;
use App\Http\Controllers\Analytics\RadiologyTatController;
use App\Http\Controllers\Api\Radiology\RadiologyFlowBoardController;
use App\Http\Controllers\RadiologyController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RadiologyRouteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_and_study_pages_keep_their_canonical_bookmarks_and_actions(): void
    {
        $expected = [
            'radiology.flow-board' => ['radiology', RadiologyController::class.'@index'],
            'radiology.worklist' => ['radiology/worklist', RadiologyController::class.'@worklist'],
            'radiology.modality' => ['radiology/modality', RadiologyController::class.'@modality'],
            'radiology.reads' => ['radiology/reads', RadiologyController::class.'@reads'],
            'analytics.radiology-tat' => ['analytics/radiology-tat', RadiologyTatController::class],
            'analytics.ir-utilization' => ['analytics/ir-utilization', IrSuiteController::class],
        ];

        foreach ($expected as $name => [$uri, $action]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing {$name}.");
            $this->assertSame($uri, $route->uri(), $name);
            $this->assertContains('GET', $route->methods(), $name);
            $this->assertSame($action, $route->getActionName(), $name);
            $this->assertContains('App\\Http\\Middleware\\SessionAuthMiddleware', $route->gatherMiddleware(), $name);
        }

        $webRoutes = file_get_contents(base_path('routes/web.php'));
        $radiologyOffset = strpos($webRoutes, "Route::prefix('radiology')->name('radiology.')");
        $rtdcOffset = strpos($webRoutes, "Route::prefix('rtdc')->name('rtdc.')");

        $this->assertIsInt($radiologyOffset, 'The Radiology route group is missing from routes/web.php.');
        $this->assertIsInt($rtdcOffset, 'The RTDC route group is missing from routes/web.php.');
        $this->assertLessThan($rtdcOffset, $radiologyOffset, 'Radiology routes must remain registered before RTDC routes.');
    }

    public function test_read_apis_and_barrier_action_have_stable_named_ownership(): void
    {
        $expected = [
            'api.radiology.flow-board' => ['api/radiology/flow-board', 'show', 'GET'],
            'api.radiology.worklist' => ['api/radiology/worklist', 'worklist', 'GET'],
            'api.radiology.modality' => ['api/radiology/modality', 'modality', 'GET'],
            'api.radiology.reads' => ['api/radiology/reads', 'reads', 'GET'],
            'api.radiology.tat' => ['api/radiology/tat', 'tat', 'GET'],
            'api.radiology.ir-utilization' => ['api/radiology/ir-utilization', 'irSuite', 'GET'],
            'api.radiology.barriers.store' => ['api/radiology/barriers', 'storeBarrier', 'POST'],
        ];

        foreach ($expected as $name => [$uri, $method, $verb]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing {$name}.");
            $this->assertSame($uri, $route->uri(), $name);
            $this->assertContains($verb, $route->methods(), $name);
            $this->assertSame(RadiologyFlowBoardController::class.'@'.$method, $route->getActionName(), $name);
            $this->assertContains('auth', $route->gatherMiddleware(), $name);
        }
    }

    public function test_patient_detail_and_barrier_capabilities_are_independent_and_role_scoped(): void
    {
        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $radiologist = User::factory()->create(['role' => 'radiologist', 'must_change_password' => false]);
        $radiologyManager = User::factory()->create(['role' => 'radiology-manager', 'must_change_password' => false]);

        $this->assertFalse(Gate::forUser($frontline)->allows('viewAncillaryPatientDetail'));
        $this->assertFalse(Gate::forUser($frontline)->allows('manageAncillaryBarriers'));

        $this->assertTrue(Gate::forUser($radiologist)->allows('viewAncillaryPatientDetail'));
        $this->assertFalse(Gate::forUser($radiologist)->allows('manageAncillaryBarriers'));

        $this->assertTrue(Gate::forUser($radiologyManager)->allows('viewAncillaryPatientDetail'));
        $this->assertTrue(Gate::forUser($radiologyManager)->allows('manageAncillaryBarriers'));
    }
}
