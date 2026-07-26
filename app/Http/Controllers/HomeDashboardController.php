<?php

namespace App\Http\Controllers;

use App\Services\Home\HomeCensusService;
use App\Services\Home\HomeCommandService;
use App\Services\Home\HomeLogisticsService;
use App\Services\Home\HomeReferralService;
use App\Services\Home\HomeTransitionService;
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

    public function command(HomeCommandService $command): InertiaResponse
    {
        return Inertia::render('Home/Command', $command->build());
    }

    public function referrals(HomeReferralService $referrals): InertiaResponse
    {
        return Inertia::render('Home/Referrals', $referrals->build());
    }

    public function transitions(HomeTransitionService $transitions): InertiaResponse
    {
        return Inertia::render('Home/Transitions', $transitions->build());
    }

    public function logistics(HomeLogisticsService $logistics): InertiaResponse
    {
        return Inertia::render('Home/Logistics', $logistics->build());
    }
}
