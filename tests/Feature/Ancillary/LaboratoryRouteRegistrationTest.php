<?php

namespace Tests\Feature\Ancillary;

use App\Http\Controllers\Analytics\LabTatController;
use App\Http\Controllers\Api\Lab\LabFlowBoardController;
use App\Http\Controllers\LabController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class LaboratoryRouteRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_and_study_pages_keep_canonical_bookmarks_actions_and_authentication(): void
    {
        $expected = [
            'lab.flow-board' => ['lab', LabController::class.'@index'],
            'lab.specimens' => ['lab/specimens', LabController::class.'@specimens'],
            'lab.pending-decisions' => ['lab/pending-decisions', LabController::class.'@pendingDecisions'],
            'lab.blood-bank' => ['lab/blood-bank', LabController::class.'@bloodBank'],
            'lab.anatomic-path' => ['lab/anatomic-path', LabController::class.'@anatomicPathology'],
            'analytics.lab-tat' => ['analytics/lab-tat', LabTatController::class],
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

    public function test_read_apis_and_barrier_mutation_have_stable_named_ownership(): void
    {
        $expected = [
            'api.lab.flow-board' => ['api/lab/flow-board', 'show', 'GET'],
            'api.lab.specimens' => ['api/lab/specimens', 'specimens', 'GET'],
            'api.lab.pending-decisions' => ['api/lab/pending-decisions', 'pendingDecisions', 'GET'],
            'api.lab.blood-bank' => ['api/lab/blood-bank', 'bloodBank', 'GET'],
            'api.lab.anatomic-path' => ['api/lab/anatomic-path', 'anatomicPathology', 'GET'],
            'api.lab.tat' => ['api/lab/tat', 'tat', 'GET'],
            'api.lab.barriers.store' => ['api/lab/barriers', 'storeBarrier', 'POST'],
        ];

        foreach ($expected as $name => [$uri, $method, $verb]) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing {$name}.");
            $this->assertSame($uri, $route->uri(), $name);
            $this->assertContains($verb, $route->methods(), $name);
            $this->assertSame(LabFlowBoardController::class.'@'.$method, $route->getActionName(), $name);
            $this->assertContains('auth', $route->gatherMiddleware(), $name);
            $this->assertContains('throttle:60,1', $route->gatherMiddleware(), $name);
            $this->assertSame(1, collect(app('router')->getRoutes())->filter(
                fn (Route $candidate): bool => $candidate->uri() === $uri && in_array($verb, $candidate->methods(), true),
            )->count(), "{$verb} {$uri} must have one owner.");
        }
    }

    public function test_every_read_api_requires_authentication_and_non_read_methods_are_not_registered(): void
    {
        foreach (['flow-board', 'specimens', 'pending-decisions', 'blood-bank', 'anatomic-path', 'tat'] as $endpoint) {
            $this->getJson('/api/lab/'.$endpoint)->assertUnauthorized();
            $this->postJson('/api/lab/'.$endpoint)->assertMethodNotAllowed();
        }

        $this->postJson('/api/lab/barriers', [])->assertUnauthorized();
        $this->getJson('/api/lab/barriers')->assertMethodNotAllowed();
    }

    public function test_patient_detail_and_barrier_capabilities_are_independent_and_role_scoped(): void
    {
        $frontline = User::factory()->create(['role' => 'user', 'must_change_password' => false]);
        $laboratoryViewer = User::factory()->create(['role' => 'hospitalist', 'must_change_password' => false]);
        $operationsManager = User::factory()->create(['role' => 'bed-manager', 'must_change_password' => false]);

        $this->assertFalse(Gate::forUser($frontline)->allows('viewAncillaryPatientDetail'));
        $this->assertFalse(Gate::forUser($frontline)->allows('manageAncillaryBarriers'));

        $this->assertTrue(Gate::forUser($laboratoryViewer)->allows('viewAncillaryPatientDetail'));
        $this->assertFalse(Gate::forUser($laboratoryViewer)->allows('manageAncillaryBarriers'));

        $this->assertTrue(Gate::forUser($operationsManager)->allows('viewAncillaryPatientDetail'));
        $this->assertTrue(Gate::forUser($operationsManager)->allows('manageAncillaryBarriers'));
    }
}
