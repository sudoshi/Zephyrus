<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lab\LabTatAnalyticsRequest;
use App\Services\Lab\LabTatAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

final class LabTatController extends Controller
{
    public function __invoke(LabTatAnalyticsRequest $request, LabTatAnalyticsService $analytics): Response
    {
        return Inertia::render('Analytics/LabTat', [
            'labTat' => $analytics->build($request->validated()),
        ]);
    }
}
