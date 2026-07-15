<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\PharmacyTatAnalyticsRequest;
use App\Services\Pharmacy\PharmacyTatAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

final class PharmacyTatController extends Controller
{
    public function __invoke(PharmacyTatAnalyticsRequest $request, PharmacyTatAnalyticsService $analytics): Response
    {
        return Inertia::render('Analytics/PharmacyTat', [
            'pharmacyTat' => $analytics->build($request->validated()),
        ]);
    }
}
