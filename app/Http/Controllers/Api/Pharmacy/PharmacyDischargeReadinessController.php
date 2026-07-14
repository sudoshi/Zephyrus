<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\PharmacyDischargeReadinessRequest;
use App\Services\Pharmacy\PharmacyDischargeReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class PharmacyDischargeReadinessController extends Controller
{
    public function show(PharmacyDischargeReadinessRequest $request, PharmacyDischargeReadinessService $readiness): JsonResponse
    {
        return response()->json($readiness->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }
}
