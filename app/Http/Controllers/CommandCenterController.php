<?php

// app/Http/Controllers/CommandCenterController.php

namespace App\Http\Controllers;

use App\Services\CommandCenterDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CommandCenterController extends Controller
{
    public function __construct(
        private readonly CommandCenterDataService $dataService,
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
}
