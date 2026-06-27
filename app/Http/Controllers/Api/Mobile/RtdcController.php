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
                $safe = (int) $this->acuity->adjustedCapacity($unit->unit_id);

                return [
                    'unit_id' => $unit->unit_id,
                    'name' => $unit->name,
                    'type' => $unit->type,
                    'staffed_bed_count' => $unit->staffed_bed_count,
                    'occupied' => $occupied,
                    'available' => $available,
                    'blocked' => $beds->whereIn('status', ['blocked', 'dirty'])->count(),
                    'safe_capacity' => $safe,
                    'bed_need' => max(0, $occupied - $safe),
                    'status' => $this->capacityStatus($occupied, $safe),
                ];
            })
            ->values();

        return $this->envelope($units, links: ['web' => url('/rtdc/bed-tracking')]);
    }

    /**
     * Map occupancy vs. safe capacity to the rationed status vocabulary.
     */
    private function capacityStatus(int $occupied, int $safe): string
    {
        if ($safe <= 0) {
            return 'info';
        }

        $ratio = $occupied / max(1, $safe);

        return match (true) {
            $ratio >= 1.0 => 'critical',
            $ratio >= 0.9 => 'warning',
            default => 'success',
        };
    }
}
