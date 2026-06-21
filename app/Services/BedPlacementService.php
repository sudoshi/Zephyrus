<?php

namespace App\Services;

use App\Exceptions\BedUnavailableException;
use App\Exceptions\UnsafePlacementException;
use App\Models\Bed;
use App\Models\BedPlacementDecision;
use App\Models\BedRequest;
use App\Rtdc\EventDispatcher;
use App\Rtdc\Events\CanonicalEvent;
use App\Rtdc\Optimizer\BedFeasibility;
use App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer;
use App\Rtdc\Optimizer\RankedRecommendations;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the bed-placement loop: recommend -> human decides -> (on accept)
 * dispatch the canonical EncounterStarted event so the live census updates.
 * Every decision is audited for traceability + future weight-tuning.
 */
class BedPlacementService
{
    public function __construct(
        private readonly BedAssignmentOptimizer $optimizer,
        private readonly EventDispatcher $dispatcher,
        private readonly BedFeasibility $feasibility,
    ) {}

    public function recommend(BedRequest $request): RankedRecommendations
    {
        return $this->optimizer->recommend($request);
    }

    public function decide(BedRequest $request, string $action, ?int $chosenBedId, ?string $reason, ?int $decidedBy): BedPlacementDecision
    {
        $recommended = $this->optimizer->recommend($request);
        $topBedId = $recommended->top()?->bedId;

        return DB::transaction(function () use ($request, $action, $chosenBedId, $reason, $decidedBy, $recommended, $topBedId) {
            $decision = BedPlacementDecision::create([
                'bed_request_id' => $request->bed_request_id,
                'recommended_bed_id' => $topBedId,
                'chosen_bed_id' => $chosenBedId,
                'action' => $action,
                'reason' => $reason,
                'score_snapshot' => $recommended->toArray(),
                'decided_by' => $decidedBy,
            ]);

            if (in_array($action, ['accepted', 'edited'], true) && $chosenBedId !== null) {
                // Re-check availability + hard constraints server-side; the client's
                // chosen_bed_id is never trusted. Locking the row serializes concurrent
                // accepts so the same bed cannot be claimed twice. Throwing rolls back
                // the transaction → no audit row, no placement for a failed attempt.
                $bed = Bed::where('bed_id', $chosenBedId)->where('status', 'available')->lockForUpdate()->first();
                if ($bed === null) {
                    throw new BedUnavailableException("bed {$chosenBedId} is not available");
                }
                $violation = $this->feasibility->violation($request, $bed->loadMissing('unit'));
                if ($violation !== null) {
                    throw new UnsafePlacementException($violation);
                }
                $this->dispatcher->dispatch(CanonicalEvent::encounterStarted(
                    $request->patient_ref,
                    $bed->unit_id,
                    $request->acuity_tier,
                    now(),
                    $bed->bed_id,
                ));
                $request->update(['status' => 'placed']);
            }

            return $decision;
        });
    }
}
