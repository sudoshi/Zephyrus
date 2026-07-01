<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Exceptions\BedUnavailableException;
use App\Exceptions\UnsafePlacementException;
use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\BedRequest;
use App\Models\EdVisit;
use App\Models\RtdcPrediction;
use App\Models\Unit;
use App\Services\AcuityService;
use App\Services\BarrierService;
use App\Services\BedPlacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * RTDC reads + the unit/house actions a frontline or ops worker takes from mobile.
 *
 *   GET  /api/mobile/v1/rtdc/census                              (mobile:read)
 *   GET  /api/mobile/v1/rtdc/house                               (mobile:read)  — bed-manager roll-up
 *   GET  /api/mobile/v1/rtdc/bed-requests                        (mobile:read)  — pending placements
 *   GET  /api/mobile/v1/rtdc/bed-requests/{id}/recommendations   (mobile:read)  — ranked beds + scores
 *   POST /api/mobile/v1/rtdc/bed-requests/{id}/decision          (mobile:act)   — place / reject
 *   POST /api/mobile/v1/rtdc/barriers/{id}/resolve               (mobile:act)
 *
 * Reads are PHI-minimized (no patient_ref/sex). Mutations delegate to {@see BedPlacementService}
 * and {@see BarrierService} verbatim — the placement engine re-checks safety + availability
 * server-side (the client's chosen bed is never trusted) and dispatches the census event.
 */
class RtdcController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(
        private readonly AcuityService $acuity,
        private readonly BarrierService $barriers,
        private readonly BedPlacementService $placement,
    ) {}

    public function census(): JsonResponse
    {
        $units = Unit::with('beds')->where('is_deleted', false)->get()
            ->map(fn (Unit $unit) => $this->unitRow($unit))
            ->values();

        return $this->envelope($units, links: ['web' => url('/rtdc/bed-tracking')]);
    }

    /** The bed-manager / supervisor house roll-up: occupancy, net bed-need, placements, boarding. */
    public function house(): JsonResponse
    {
        $rows = Unit::with('beds')->where('is_deleted', false)->get()
            ->map(fn (Unit $unit) => $this->unitRow($unit))
            ->values();

        $occupied = (int) $rows->sum('occupied');
        $staffed = (int) $rows->sum('staffed_bed_count');

        $netBedNeed = (int) RtdcPrediction::where('service_date', now()->toDateString())
            ->where('horizon', 'by_2pm')
            ->where('is_deleted', false)
            ->sum('bed_need');

        $boarding = EdVisit::where('is_deleted', false)
            ->whereNotNull('admit_decision_at')
            ->whereNull('bed_assigned_at')
            ->count();

        return $this->envelope([
            'occupancy' => [
                'occupied' => $occupied,
                'staffed' => $staffed,
                'percent' => $staffed > 0 ? (int) round(100 * $occupied / $staffed) : 0,
            ],
            'net_bed_need' => $netBedNeed,
            'pending_placements' => BedRequest::pending()->count(),
            'ed_boarding' => $boarding,
            'units' => $rows,
        ], links: ['web' => url('/rtdc/bed-tracking')]);
    }

    /** The pending bed-placement worklist (PHI-minimized). */
    public function placements(): JsonResponse
    {
        $items = BedRequest::pending()->orderBy('created_at')->get()
            ->map(fn (BedRequest $r) => [
                'id' => $r->bed_request_id,
                'source' => $r->source,
                'service' => $r->service,
                'acuity_tier' => $r->acuity_tier,
                'tier' => match (true) {
                    $r->acuity_tier !== null && $r->acuity_tier <= 1 => 'critical',
                    $r->acuity_tier !== null && $r->acuity_tier <= 2 => 'warning',
                    default => 'info',
                },
                'isolation_required' => $r->isolation_required,
                'required_unit_type' => $r->required_unit_type,
                'at' => optional($r->created_at)->toIso8601String(),
            ])
            ->values();

        return $this->envelope($items, meta: ['count' => $items->count()], links: ['web' => url('/rtdc/bed-placement')]);
    }

    /** Ranked bed recommendations for a placement (transparent score + safety chips). */
    public function placementRecommendations(int $id): JsonResponse
    {
        $request = BedRequest::where('bed_request_id', $id)->where('is_deleted', false)->firstOrFail();
        $ranked = $this->placement->recommend($request);

        $top = collect($ranked->recommendations)->take(3)->map(fn ($r) => [
            'bed_id' => $r->bedId,
            'bed_label' => $r->bedLabel,
            'unit_name' => $r->unitName,
            'score' => $r->score,
            'chips' => $r->chips,
        ])->values();

        return $this->envelope(
            ['recommendations' => $top, 'runner_up_delta' => $ranked->runnerUpDelta()],
            links: ['web' => url('/rtdc/bed-placement')],
        );
    }

    /** Place (accept a chosen bed) or reject a pending request. Engine re-validates safety. */
    public function placeBed(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['accepted', 'rejected'])],
            'chosen_bed_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $bedRequest = BedRequest::where('bed_request_id', $id)->where('is_deleted', false)->firstOrFail();

        try {
            $this->placement->decide(
                $bedRequest,
                $validated['action'],
                $validated['chosen_bed_id'] ?? null,
                $validated['reason'] ?? null,
                $request->user()?->id,
            );
        } catch (UnsafePlacementException $e) {
            return $this->envelope(['error' => $e->getMessage()], status: 422);
        } catch (BedUnavailableException $e) {
            return $this->envelope(['error' => $e->getMessage()], status: 409);
        }

        return $this->envelope(
            ['id' => $id, 'action' => $validated['action'], 'status' => $bedRequest->fresh()->status],
            links: ['web' => url('/rtdc/bed-placement')],
        );
    }

    /**
     * POST /api/mobile/v1/rtdc/barriers/{id}/resolve — clear an open discharge barrier.
     * PHI-minimized ack only (the barrier's free-text description never returns to mobile).
     */
    public function resolveBarrier(int $id): JsonResponse
    {
        $this->barriers->resolve($id);

        return $this->envelope(
            ['id' => $id, 'status' => 'resolved'],
            links: ['web' => url('/rtdc/bed-tracking')],
        );
    }

    /** One PHI-free census row: counts, acuity-safe headroom (can_admit), and rationed status. */
    private function unitRow(Unit $unit): array
    {
        $beds = $unit->beds;
        $occupied = $beds->where('status', 'occupied')->count();
        $available = $beds->where('status', 'available')->count();
        $staffed = (int) $unit->staffed_bed_count;

        // What the unit can actually accept = the smaller of nurse-safety headroom and clean beds.
        $canAdmit = max(0, min((int) $this->acuity->adjustedCapacity($unit->unit_id), $available));

        return [
            'unit_id' => $unit->unit_id,
            'name' => $unit->name,
            'type' => $unit->type,
            'staffed_bed_count' => $staffed,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $beds->whereIn('status', ['blocked', 'dirty'])->count(),
            'can_admit' => $canAdmit,
            'bed_need' => max(0, $occupied - $staffed),
            'status' => $this->capacityStatus($occupied, $staffed, $canAdmit),
        ];
    }

    /**
     * Rationed status from physical occupancy (occupied / staffed beds), escalated to at least
     * "warning" when the unit can't safely admit anyone (no nurse-safety headroom or no clean bed).
     */
    private function capacityStatus(int $occupied, int $staffed, int $canAdmit): string
    {
        if ($staffed <= 0) {
            return 'info';
        }

        $ratio = $occupied / $staffed;

        return match (true) {
            $ratio >= 1.0 => 'critical',
            $ratio >= 0.9 || $canAdmit <= 0 => 'warning',
            default => 'success',
        };
    }
}
