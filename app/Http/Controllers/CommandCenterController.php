<?php

// app/Http/Controllers/CommandCenterController.php

namespace App\Http\Controllers;

use App\Services\CommandCenterDataService;
use App\Services\CommandCenterDrilldownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CommandCenterController extends Controller
{
    public function __construct(
        private readonly CommandCenterDataService $dataService,
        private readonly CommandCenterDrilldownService $drilldownService,
    ) {}

    /**
     * Display the Hospital Operations Command Center (main dashboard).
     */
    public function index(Request $request): InertiaResponse
    {
        $request->session()->put('workflow', 'superuser');

        return Inertia::render('Dashboard/CommandCenter', [
            'data' => $this->dataService->build(),
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
