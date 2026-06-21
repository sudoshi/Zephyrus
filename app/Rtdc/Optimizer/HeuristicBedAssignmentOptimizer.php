<?php

namespace App\Rtdc\Optimizer;

use App\Models\Bed;
use App\Models\BedRequest;
use App\Rtdc\Optimizer\Contracts\BedAssignmentOptimizer;
use App\Services\AcuityService;

/**
 * Transparent weighted-scoring bed recommender. Hard constraints prune the
 * feasible set (safety = the region we search); soft terms rank it. Pure read,
 * sub-millisecond. Replaceable by a CP-SAT service behind BedAssignmentOptimizer.
 */
class HeuristicBedAssignmentOptimizer implements BedAssignmentOptimizer
{
    // Soft-term weights (tunable; later learned from bed_placement_decisions overrides).
    private const W_UNIT_TYPE_MATCH = 10;

    private const W_ACUITY_HEADROOM = 20;

    private const W_OCCUPANCY_BALANCE = 15;

    private const W_ISOLATION_FRAGMENTATION = 25;

    public function __construct(private readonly AcuityService $acuity) {}

    public function recommend(BedRequest $request): RankedRecommendations
    {
        $beds = Bed::available()->with('unit')->get();
        $recommendations = [];
        $excluded = [];

        foreach ($beds as $bed) {
            $reason = $this->hardConstraintViolation($request, $bed);
            if ($reason !== null) {
                $excluded[] = new ExcludedBed($bed->bed_id, $reason);

                continue;
            }
            $recommendations[] = $this->score($request, $bed);
        }

        usort($recommendations, fn (Recommendation $a, Recommendation $b) => $b->score <=> $a->score);

        return new RankedRecommendations($recommendations, $excluded);
    }

    private function hardConstraintViolation(BedRequest $req, Bed $bed): ?string
    {
        if ($req->required_unit_type !== 'any' && $bed->unit->type !== $req->required_unit_type) {
            return "capability: needs {$req->required_unit_type}, bed is {$bed->unit->type}";
        }
        if ($req->isolation_required !== 'none' && ! $bed->isolation_capable) {
            return "isolation: needs {$req->isolation_required}, bed not isolation-capable";
        }
        if (! $this->acuity->canAccept($bed->unit_id, $req->acuity_tier)) {
            return 'safety: unit nursing workload cannot safely accept this acuity';
        }

        return null;
    }

    private function score(BedRequest $req, Bed $bed): Recommendation
    {
        $unit = $bed->unit;
        $remaining = $this->acuity->remainingWorkload($bed->unit_id);
        $available = Bed::available()->where('unit_id', $bed->unit_id)->count();
        $staffed = max(1, $unit->staffed_bed_count);

        $breakdown = [];

        $typeMatch = ($req->required_unit_type !== 'any' && $unit->type === $req->required_unit_type) ? self::W_UNIT_TYPE_MATCH : 0;
        $breakdown[] = ['term' => 'unit_type_match', 'value' => $typeMatch];

        $headroom = (int) round(self::W_ACUITY_HEADROOM * max(0, $remaining) / $staffed);
        $breakdown[] = ['term' => 'acuity_headroom', 'value' => $headroom];

        $occupancy = (int) round(self::W_OCCUPANCY_BALANCE * $available / $staffed);
        $breakdown[] = ['term' => 'occupancy_balance', 'value' => $occupancy];

        $fragmentation = ($bed->isolation_capable && $req->isolation_required === 'none') ? -self::W_ISOLATION_FRAGMENTATION : 0;
        $breakdown[] = ['term' => 'isolation_fragmentation', 'value' => $fragmentation];

        $score = $typeMatch + $headroom + $occupancy + $fragmentation;

        $chips = [
            ['label' => 'Isolation '.($req->isolation_required === 'none' ? 'n/a' : 'match'), 'ok' => true],
            ['label' => 'Acuity headroom: '.(int) floor(max(0, $remaining)), 'ok' => $remaining > 0],
            ['label' => 'Capability '.($unit->type === $req->required_unit_type || $req->required_unit_type === 'any' ? 'OK' : 'mismatch'), 'ok' => true],
        ];

        return new Recommendation(
            bedId: $bed->bed_id,
            bedLabel: $bed->label,
            unitId: $bed->unit_id,
            unitName: $unit->name,
            score: $score,
            breakdown: $breakdown,
            chips: $chips,
        );
    }
}
