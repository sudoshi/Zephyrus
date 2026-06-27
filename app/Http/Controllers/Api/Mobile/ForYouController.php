<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\Barrier;
use App\Models\BedRequest;
use App\Models\Unit;
use App\Services\AcuityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * GET /api/mobile/v1/for-you
 *
 * The "For You" queue (Phase 1) — a single prioritized list of things that need action,
 * composed from pending bed placements, open discharge barriers, and units at/over safe
 * capacity. PHI-minimized (no patient identifiers, no free-text descriptions); ranked by the
 * rationed tier vocabulary so the most urgent item is first.
 */
class ForYouController extends Controller
{
    use RendersMobileEnvelope;

    public function __construct(private readonly AcuityService $acuity) {}

    public function index(): JsonResponse
    {
        $items = new Collection;

        foreach (BedRequest::pending()->orderBy('created_at')->get() as $r) {
            $items->push([
                'id' => 'bedreq-'.$r->bed_request_id,
                'type' => 'bed_request',
                'tier' => match (true) {
                    $r->acuity_tier !== null && $r->acuity_tier <= 1 => 'critical',
                    $r->acuity_tier !== null && $r->acuity_tier <= 2 => 'warning',
                    default => 'info',
                },
                'title' => 'Bed placement needed',
                'subtitle' => trim(($r->service ?: 'Unassigned').' · needs '.($r->required_unit_type ?: 'any unit')
                    .($r->isolation_required && $r->isolation_required !== 'none' ? ' · '.$r->isolation_required.' isolation' : '')),
                'unit' => null,
                'at' => optional($r->created_at)->toIso8601String(),
            ]);
        }

        foreach (Barrier::open()->with('unit')->orderBy('opened_at')->get() as $b) {
            $items->push([
                'id' => 'barrier-'.$b->barrier_id,
                'type' => 'barrier',
                'tier' => in_array($b->category, ['placement', 'medical'], true) ? 'warning' : 'info',
                'title' => ucfirst($b->category ?: 'Discharge').' barrier',
                'subtitle' => $b->reason_code ?: 'Open barrier to clear',
                'unit' => $b->unit?->name,
                'at' => optional($b->opened_at)->toIso8601String(),
            ]);
        }

        foreach (Unit::with('beds')->where('is_deleted', false)->get() as $unit) {
            $occupied = $unit->beds->where('status', 'occupied')->count();
            $safe = (int) $this->acuity->adjustedCapacity($unit->unit_id);
            if ($safe > 0 && $occupied / $safe >= 1.0) {
                $items->push([
                    'id' => 'cap-'.$unit->unit_id,
                    'type' => 'capacity',
                    'tier' => 'critical',
                    'title' => $unit->name.' at capacity',
                    'subtitle' => $occupied.' / '.$safe.' safe beds occupied',
                    'unit' => $unit->name,
                    'at' => now()->toIso8601String(),
                ]);
            }
        }

        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'success' => 3];
        $sorted = $items
            ->sortBy(fn ($i) => sprintf('%d-%s', $rank[$i['tier']] ?? 9, $i['at'] ?? ''))
            ->values();

        return $this->envelope($sorted, meta: ['count' => $sorted->count()], links: ['web' => url('/rtdc/bed-tracking')]);
    }
}
