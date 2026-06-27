<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\PrimetimeUtilizationService;
use Inertia\Inertia;

class PrimetimeUtilizationController extends Controller
{
    public function index(PrimetimeUtilizationService $primetimeUtilization)
    {
        return Inertia::render('Analytics/PrimetimeUtilization', [
            'primetime' => $primetimeUtilization->build(),
        ]);
    }
}
