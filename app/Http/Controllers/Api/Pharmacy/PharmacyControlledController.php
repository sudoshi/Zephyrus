<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\PharmacyControlledRequest;
use App\Services\Pharmacy\ControlledSubstanceOperationsService;
use Illuminate\Http\JsonResponse;

final class PharmacyControlledController extends Controller
{
    /**
     * The controlled-substance operational aggregate view. The FormRequest
     * authorizes against viewControlledSubstanceOperations, so an unauthorized
     * caller is denied with a clean 403 before the service is ever constructed —
     * the response carries no controlled data and does not leak existence detail
     * beyond the standard authorization envelope.
     */
    public function show(PharmacyControlledRequest $request, ControlledSubstanceOperationsService $controlled): JsonResponse
    {
        return response()->json($controlled->build())
            ->withHeaders(['Cache-Control' => 'private, no-cache, no-store']);
    }
}
