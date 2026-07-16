<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeWorkflowRequest;
use App\Http\Requests\Improvement\StorePdsaCycleRequest;
use App\Models\PdsaCycle;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            'overview' => app(\App\Services\Dashboard\PerioperativeMetricsService::class)->build(),
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
            'reportedBarriers' => DB::table('prod.barriers as b')
                ->leftJoin('prod.units as u', 'u.unit_id', '=', 'b.unit_id')
                ->leftJoin('hosp_ref.ancillary_barrier_reasons as r', 'r.reason_code', '=', 'b.reason_code')
                ->where('b.status', 'open')->where('b.is_deleted', false)
                ->orderByDesc('b.opened_at')->limit(20)
                ->get(['b.barrier_id', 'b.category', 'b.reason_code', 'b.description', 'b.owner', 'b.opened_at', 'u.name as unit_name', 'r.department', 'r.label as reason_label'])
                ->map(fn (object $barrier): array => [
                    'barrierId' => (int) $barrier->barrier_id,
                    'category' => $barrier->category,
                    'reasonCode' => $barrier->reason_code,
                    'label' => $barrier->reason_label ?? 'Operational barrier',
                    'description' => $barrier->description,
                    'owner' => $barrier->owner,
                    'unitLabel' => $barrier->unit_name,
                    'department' => $barrier->department,
                    'openedAt' => Carbon::parse($barrier->opened_at)->toAtomString(),
                ])->all(),
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
     * Persist a new PDSA cycle from the New PDSA Cycle form. Captures the full
     * Plan phase (objective, rationale, prediction) and the target completion;
     * the cycle starts active. Redirects to the new cycle's detail page.
     */
    public function pdsaStore(StorePdsaCycleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $cycle = PdsaCycle::create([
            'title' => $data['title'],
            'objective' => $data['objective'] ?? null,
            'rationale' => $data['rationale'] ?? null,
            'prediction' => $data['prediction'] ?? null,
            'owner' => $data['owner'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'status' => 'active',
            'started_at' => now(),
            'target_date' => ! empty($data['dueDate']) ? Carbon::parse($data['dueDate']) : null,
            'is_deleted' => false,
        ]);

        return redirect()
            ->route('improvement.pdsa.show', $cycle->pdsa_cycle_id)
            ->with('success', 'PDSA cycle created.');
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
