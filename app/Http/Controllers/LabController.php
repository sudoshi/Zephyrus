<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lab\AnatomicPathologyRequest;
use App\Http\Requests\Lab\BloodBankReadinessRequest;
use App\Http\Requests\Lab\LabDecisionPendingRequest;
use App\Http\Requests\Lab\LabFlowBoardRequest;
use App\Http\Requests\Lab\LabSpecimenRequest;
use App\Services\Lab\AnatomicPathologyService;
use App\Services\Lab\BloodBankReadinessService;
use App\Services\Lab\LabDecisionPendingService;
use App\Services\Lab\LabFlowBoardService;
use App\Services\Lab\LabSpecimenService;
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

    public function specimens(LabSpecimenRequest $request, LabSpecimenService $specimens): Response
    {
        return Inertia::render('Lab/Specimens', [
            'specimens' => $specimens->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')),
        ]);
    }

    public function pendingDecisions(LabDecisionPendingRequest $request, LabDecisionPendingService $pending): Response
    {
        return Inertia::render('Lab/PendingDecisions', [
            'pendingDecisions' => $pending->build(
                $request->validated(),
                Gate::allows('manageAncillaryBarriers'),
                Gate::allows('viewAncillaryPatientDetail'),
            ),
        ]);
    }

    public function bloodBank(BloodBankReadinessRequest $request, BloodBankReadinessService $bloodBank): Response
    {
        return Inertia::render('Lab/BloodBank', [
            'bloodBank' => $bloodBank->build($request->validated()),
        ]);
    }

    public function anatomicPathology(AnatomicPathologyRequest $request, AnatomicPathologyService $pathology): Response
    {
        return Inertia::render('Lab/AnatomicPathology', [
            'pathology' => $pathology->build($request->validated()),
        ]);
    }
}
