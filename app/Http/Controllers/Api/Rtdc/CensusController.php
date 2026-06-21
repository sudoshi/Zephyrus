<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Http\JsonResponse;

class CensusController extends Controller
{
    public function __construct(private readonly AcuityService $acuity) {}

    public function units(): JsonResponse
    {
        $units = Unit::with('beds')->where('is_deleted', false)->get()->map(function (Unit $unit) {
            $beds = $unit->beds;

            return [
                'unit_id' => $unit->unit_id,
                'name' => $unit->name,
                'type' => $unit->type,
                'staffed_bed_count' => $unit->staffed_bed_count,
                'census' => [
                    'occupied' => $beds->where('status', 'occupied')->count(),
                    'available' => $beds->where('status', 'available')->count(),
                    'blocked' => $beds->whereIn('status', ['blocked', 'dirty'])->count(),
                    'acuity_adjusted_capacity' => $this->acuity->adjustedCapacity($unit->unit_id),
                ],
            ];
        });

        return response()->json(['data' => $units]);
    }
}
