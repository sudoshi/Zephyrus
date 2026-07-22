<?php

namespace App\Http\Controllers;

use App\Services\CarePathways\CarePathwayDemoScenarioService;
use Inertia\Inertia;
use Inertia\Response;

final class CarePathwayDemoPageController extends Controller
{
    public function __invoke(CarePathwayDemoScenarioService $scenario): Response
    {
        return Inertia::render('CarePathways/Demo', [
            'initialScenario' => $scenario->scenario(),
        ]);
    }
}
