<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lab\LabFlowBoardRequest;
use App\Services\Lab\LabFlowBoardService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class LabController extends Controller
{
    public function index(LabFlowBoardRequest $request, LabFlowBoardService $flowBoard): Response
    {
        return Inertia::render('Lab/FlowBoard', [
            'flowBoard' => $flowBoard->build($request->validated(), Gate::allows('manageAncillaryBarriers'), Gate::allows('viewAncillaryPatientDetail')),
        ]);
    }
}
