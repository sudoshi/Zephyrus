<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Radiology\IrSuiteAnalyticsRequest;
use App\Services\Radiology\IrSuiteAnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

final class IrSuiteController extends Controller
{
    public function __invoke(IrSuiteAnalyticsRequest $request, IrSuiteAnalyticsService $analytics): Response
    {
        return Inertia::render('Analytics/IrSuite', [
            'irSuite' => $analytics->build($request->validated()),
        ]);
    }
}
