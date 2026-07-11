<?php

namespace App\Http\Controllers\Api\Rounds;

use App\Services\Rounds\RoundAuthorizationService;
use App\Services\Rounds\RoundProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoundScopeController extends RoundsController
{
    public function __construct(
        RoundProjectionService $projection,
        private readonly RoundAuthorizationService $authorization,
    ) {
        parent::__construct($projection);
    }

    /** Scopes the current user may start or view rounds for (Phase 1: units). */
    public function index(Request $request): JsonResponse
    {
        $units = $this->authorization->accessibleUnits($request->user())
            ->map(fn ($unit) => [
                'scope_type' => 'unit',
                'scope_key' => (string) $unit->unit_id,
                'label' => $unit->name,
                'abbreviation' => $unit->abbreviation,
            ])
            ->values();

        return response()->json(['data' => $units]);
    }
}
