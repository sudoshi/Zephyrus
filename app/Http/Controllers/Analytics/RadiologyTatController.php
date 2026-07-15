<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Radiology\RadiologyTatAnalyticsRequest;
use App\Services\Radiology\RadiologyTatAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

final class RadiologyTatController extends Controller
{
    public function __invoke(RadiologyTatAnalyticsRequest $request, RadiologyTatAnalyticsService $analytics): Response
    {
        return Inertia::render('Analytics/RadiologyTat', [
            'radiologyTat' => $analytics->build($request->validated()),
        ]);
    }
}
