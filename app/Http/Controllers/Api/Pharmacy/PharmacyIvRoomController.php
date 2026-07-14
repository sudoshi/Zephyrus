<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\PharmacyIvRoomRequest;
use App\Services\Pharmacy\PharmacyIvRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class PharmacyIvRoomController extends Controller
{
    public function show(PharmacyIvRoomRequest $request, PharmacyIvRoomService $ivRoom): JsonResponse
    {
        return response()->json($ivRoom->build($request->validated(), Gate::allows('viewAncillaryPatientDetail')))
            ->withHeaders(['Cache-Control' => 'private, no-cache']);
    }
}
