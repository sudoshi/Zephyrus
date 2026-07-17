<?php

namespace App\Http\Controllers;

use App\Services\Home\HomeCensusService;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Home Hospital (HOME) workspace pages (ACUM-PRD-HAH-001 §4.2).
 * All routes are gated by EnsureHomeHospitalEnabled (404 when the flag is off).
 */
class HomeDashboardController extends Controller
{
    public function census(HomeCensusService $census): InertiaResponse
    {
        return Inertia::render('Home/Census', $census->build());
    }
}
