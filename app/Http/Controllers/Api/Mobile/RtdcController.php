<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/mobile/v1/rtdc/census
 *
 * The first live BFF read: per-unit census with acuity-adjusted safe capacity,
 * a derived bed-need, and a status keyed to the rationed teal/amber/coral
 * vocabulary (always paired with a label on the client — never color alone).
 * Wraps the same domain logic as {@see \App\Http\Controllers\Api\Rtdc\CensusController}.
 */
class RtdcController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly AcuityService $acuity) {}

    public function census(): JsonResponse
    {
        $units = Unit::with('beds')
            ->where('is_deleted', false)
            ->get()
            ->map(function (Unit $unit) {
                $beds = $unit->beds;
                $occupied = $beds->where('status', 'occupied')->count();
                $available = $beds->where('status', 'available')->count();
                $staffed = (int) $unit->staffed_bed_count;

                // What the unit can actually accept right now = the smaller of nurse-safety
                // headroom (acuity-adjusted) and clean open beds. Either being 0 means "can't admit".
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
            })
            ->values();

        return $this->envelope($units, links: ['web' => url('/rtdc/bed-tracking')]);
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
