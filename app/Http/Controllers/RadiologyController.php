<?php

namespace App\Http\Controllers;

use App\Http\Requests\Radiology\RadiologyFlowBoardRequest;
use App\Http\Requests\Radiology\RadiologyWorklistRequest;
use App\Services\Radiology\RadiologyFlowBoardService;
use App\Services\Radiology\RadiologyWorklistService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RadiologyController extends Controller
{
    public function index(RadiologyFlowBoardRequest $request, RadiologyFlowBoardService $flowBoard): Response
    {
        return Inertia::render('Radiology/FlowBoard', [
            'flowBoard' => $flowBoard->build($request->validated(), Gate::allows('manageAncillaryBarriers')),
        ]);
    }

    public function worklist(RadiologyWorklistRequest $request, RadiologyWorklistService $worklist): Response
    {
        return Inertia::render('Radiology/Worklist', [
            'worklist' => $worklist->build($request->validated()),
        ]);
    }
}
