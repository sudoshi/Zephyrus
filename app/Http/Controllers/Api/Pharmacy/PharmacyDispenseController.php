<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\PharmacyDispenseRequest;
use App\Services\Pharmacy\PharmacyDispenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class PharmacyDispenseController extends Controller
{
    public function show(PharmacyDispenseRequest $request, PharmacyDispenseService $dispense): JsonResponse
    {
        return response()->json($dispense->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }
}
