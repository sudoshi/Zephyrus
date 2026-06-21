<?php

namespace App\Http\Controllers\Api\Rtdc;

use App\Events\Rtdc\BedMeetingUpdated;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\HuddleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HuddleController extends Controller
{
    public function __construct(private readonly HuddleService $huddles) {}

    public function open(Request $request): JsonResponse
    {
        $v = $request->validate([
            'type' => 'required|in:unit,hospital',
            'unit_id' => ['nullable', 'integer', Rule::exists(Unit::class, 'unit_id')],
            'service_date' => 'required|date',
        ]);

        $huddle = $v['type'] === 'unit'
            ? $this->huddles->openUnitHuddle($v['unit_id'], $v['service_date'], $request->user()->id)
            : $this->huddles->openHospitalHuddle($v['service_date'], $request->user()->id);

        return response()->json(['data' => $huddle]);
    }

    public function close(int $huddleId): JsonResponse
    {
        return response()->json(['data' => $this->huddles->close($huddleId)]);
    }

    public function bedMeeting(Request $request): JsonResponse
    {
        $v = $request->validate([
            'service_date' => 'required|date',
            'horizon' => 'required|in:by_2pm,by_midnight',
        ]);
        $rollup = $this->huddles->hospitalRollup($v['service_date'], $v['horizon']);
        broadcast(new BedMeetingUpdated($rollup));

        return response()->json(['data' => $rollup]);
    }
}
