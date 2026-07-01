<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Services\Operations\RoomStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * OR Board (P4 OR nurse / P7 periop manager) — GET /api/mobile/v1/or/board.
 * Reshapes RoomStatusService::build() (which self-anchors a simulated clock on the most recent
 * seeded surgery date, so the board always reads live) into the mobile room board + metrics.
 * PHI-free — patient labels are de-identified SIM tokens; we don't surface them.
 */
class ORController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly RoomStatusService $roomStatus) {}

    public function board(): JsonResponse
    {
        $built = $this->roomStatus->build();
        $names = DB::table('prod.rooms')->where('is_deleted', false)->pluck('name', 'room_id');

        $rooms = collect($built['rooms'])->map(function (array $r) use ($names) {
            $cur = $r['currentCase'];
            $next = $r['nextCase'];

            return [
                'id' => $r['number'],
                'name' => $names[$r['number']] ?? ('OR-'.$r['number']),
                'status' => $r['status'], // available | in_progress | turnover | delayed
                'tier' => match ($r['status']) {
                    'delayed' => 'critical',
                    'turnover' => 'warning',
                    'in_progress' => 'info',
                    default => 'success',
                },
                'time_remaining' => $r['timeRemaining'],
                'turnover_min' => $r['turnoverTime'],
                'current' => $cur ? [
                    'procedure' => $cur['procedure'],
                    'surgeon' => $cur['provider'],
                    'elapsed' => $cur['elapsed'],
                    'expected_duration' => $cur['expectedDuration'],
                    'expected_end' => $cur['expectedEndTime'],
                    'start_time' => $cur['startTime'],
                ] : null,
                'next' => $next ? [
                    'start_time' => $next['startTime'],
                    'procedure' => $next['procedure'],
                ] : null,
            ];
        })->values();

        $avgTurnover = (int) round((float) (DB::table('prod.case_metrics')->avg('turnover_time') ?? 0));

        return $this->envelope([
            'rooms' => $rooms,
            'metrics' => [
                'running' => $rooms->whereIn('status', ['in_progress', 'delayed'])->count(),
                'turnover' => $rooms->where('status', 'turnover')->count(),
                'available' => $rooms->where('status', 'available')->count(),
                'total' => $rooms->count(),
                'avg_turnover_min' => $avgTurnover,
            ],
        ], links: ['web' => url('/operations/room-status')]);
    }
}
