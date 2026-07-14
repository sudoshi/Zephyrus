<?php

namespace App\Http\Controllers;

use App\Http\Requests\Pharmacy\PharmacyDischargeReadinessRequest;
use App\Http\Requests\Pharmacy\PharmacyDispenseRequest;
use App\Http\Requests\Pharmacy\PharmacyFlowBoardRequest;
use App\Http\Requests\Pharmacy\PharmacyIvRoomRequest;
use App\Services\Pharmacy\PharmacyDischargeReadinessService;
use App\Services\Pharmacy\PharmacyDispenseService;
use App\Services\Pharmacy\PharmacyFlowBoardService;
use App\Services\Pharmacy\PharmacyIvRoomService;
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

    public function dischargeMeds(PharmacyDischargeReadinessRequest $request, PharmacyDischargeReadinessService $readiness): Response
    {
        return Inertia::render('Pharmacy/DischargeMeds', [
            'discharge' => $readiness->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')),
        ]);
    }

    public function ivRoom(PharmacyIvRoomRequest $request, PharmacyIvRoomService $ivRoom): Response
    {
        return Inertia::render('Pharmacy/IvRoom', [
            'ivRoom' => $ivRoom->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')),
        ]);
    }

    public function dispense(PharmacyDispenseRequest $request, PharmacyDispenseService $dispense): Response
    {
        return Inertia::render('Pharmacy/Dispense', [
            'dispense' => $dispense->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')),
        ]);
    }
}
