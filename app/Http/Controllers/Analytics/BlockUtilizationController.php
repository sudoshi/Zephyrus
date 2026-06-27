<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\BlockUtilizationService;
use Inertia\Inertia;

class BlockUtilizationController extends Controller
{
    public function index(BlockUtilizationService $blockUtilization)
    {
        return Inertia::render('Analytics/BlockUtilization', [
            'blockUtilization' => $blockUtilization->build(),
        ]);
    }
}
