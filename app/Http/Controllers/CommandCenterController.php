<?php

// app/Http/Controllers/CommandCenterController.php

namespace App\Http\Controllers;

use App\Services\CommandCenterDataService;
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
    public function index(): InertiaResponse
    {
        return Inertia::render('Dashboard/CommandCenter', [
            'data' => $this->dataService->build(),
        ]);
    }
}
