<?php

namespace App\Http\Controllers;

use App\Http\Requests\Pharmacy\PharmacyFlowBoardRequest;
use App\Services\Pharmacy\PharmacyFlowBoardService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PharmacyController extends Controller
{
    public function index(PharmacyFlowBoardRequest $request, PharmacyFlowBoardService $flowBoard): Response
    {
        return Inertia::render('Pharmacy/FlowBoard', [
            'flowBoard' => $flowBoard->build($request->validated(), Gate::allows('manageAncillaryBarriers'), Gate::allows('viewAncillaryPatientDetail')),
        ]);
    }
}
