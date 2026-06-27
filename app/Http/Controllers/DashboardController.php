<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeWorkflowRequest;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $workflow = auth()->user()->workflow_preference;

        return Inertia::render('Dashboard/Perioperative', [
            'workflow' => $workflow,
        ]);
    }

    public function changeWorkflow(ChangeWorkflowRequest $request): JsonResponse|RedirectResponse
    {
        $workflow = $request->input('workflow');
        $this->dashboardService->updateWorkflowPreference(auth()->user(), $workflow);

        if ($request->has('redirect')) {
            return redirect($request->input('redirect'));
        }

        return response()->json([
            'success' => true,
            'workflow' => $workflow,
        ]);
    }

    public function improvement(Request $request): InertiaResponse
    {
        $this->dashboardService->updateWorkflowPreference(auth()->user(), 'improvement');

        return Inertia::render('Dashboard/Improvement', [
            'workflow' => auth()->user()->workflow_preference,
            'stats' => $this->dashboardService->getImprovementStats(),
            'cycles' => [],
        ]);
    }

    public function bottlenecks(Request $request): InertiaResponse
    {
        $this->dashboardService->updateWorkflowPreference(auth()->user(), 'improvement');

        return Inertia::render('Improvement/Bottlenecks', [
            'workflow' => auth()->user()->workflow_preference,
            'bottlenecks' => $this->dashboardService->getBottleneckStats(),
        ]);
    }

    public function rootCause(Request $request): InertiaResponse
    {
        $this->dashboardService->updateWorkflowPreference(auth()->user(), 'improvement');

        return Inertia::render('Improvement/RootCause', [
            'workflow' => 'improvement',
            'rootCauses' => $this->dashboardService->getRootCauses(),
        ]);
    }

    public function overview(Request $request): RedirectResponse
    {
        return redirect()->route('dashboard.improvement');
    }

    public function opportunities(Request $request): InertiaResponse
    {
        return Inertia::render('Improvement/Opportunities', [
            'opportunities' => $this->dashboardService->getOpportunities(),
        ]);
    }

    public function library(Request $request): InertiaResponse
    {
        return Inertia::render('Improvement/Library', [
            'resources' => $this->dashboardService->getLibraryResources(),
        ]);
    }

    public function active(Request $request): InertiaResponse
    {
        return Inertia::render('Improvement/Active', [
            'cycles' => $this->dashboardService->getActiveCycles(),
        ]);
    }

    public function process(Request $request): InertiaResponse
    {
        return Inertia::render('Improvement/Process');
    }

    public function pdsaIndex(Request $request): InertiaResponse
    {
        return Inertia::render('Improvement/PDSA/Index', [
            'cycles' => $this->dashboardService->getPdsaCycles(),
        ]);
    }

    public function pdsaShow(Request $request, $id): InertiaResponse
    {
        return Inertia::render('Improvement/PDSA/Show', [
            'cycle' => $this->dashboardService->getPdsaCycle($id),
        ]);
    }

    /**
     * Set user workflow preference with URL parameters.
     */
    public function setPreference(Request $request, $workflow): RedirectResponse
    {
        $this->dashboardService->updateWorkflowPreference(auth()->user(), $workflow);

        $redirect = $request->query('redirect');
        if ($redirect) {
            return redirect($redirect);
        }

        return redirect("/dashboard/{$workflow}");
    }
}
