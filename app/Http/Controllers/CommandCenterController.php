<?php

// app/Http/Controllers/CommandCenterController.php

namespace App\Http\Controllers;

use App\Services\Cockpit\SnapshotBuilder;
use App\Services\CommandCenterDrilldownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CommandCenterController extends Controller
{
    public function __construct(
        private readonly SnapshotBuilder $snapshots,
        private readonly CommandCenterDrilldownService $drilldownService,
    ) {}

    /**
     * Display the Hospital Operations Command Center (main dashboard).
     *
     * P2: the page serves the ONE cockpit snapshot — the legacy Zod contract
     * plus the additive §3.2 sections — via SnapshotBuilder::current(), so the
     * Inertia initial render, the TanStack refresh (/api/cockpit/snapshot),
     * the drills, and Eddy all read the same cached payload.
     */
    public function index(Request $request): InertiaResponse
    {
        $request->session()->put('workflow', 'superuser');

        return Inertia::render('Dashboard/CommandCenter', [
            'data' => $this->snapshots->current(),
            'cockpitEnabled' => (bool) config('cockpit.overview_enabled'),
        ]);
    }

    /**
     * Return synthetic, 90-day minimum drill-down detail for Command Center panels.
     */
    public function drilldown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'focus' => ['nullable', 'string', 'max:120'],
            'days' => ['nullable', 'integer', 'min:1', 'max:180'],
        ]);

        return response()->json($this->drilldownService->build(
            $validated['focus'] ?? null,
            (int) ($validated['days'] ?? 90),
        ));
    }
}
