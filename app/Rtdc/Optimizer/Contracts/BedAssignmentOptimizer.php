<?php

namespace App\Rtdc\Optimizer\Contracts;

use App\Models\BedRequest;
use App\Rtdc\Optimizer\RankedRecommendations;

/**
 * The swap seam. S4 ships HeuristicBedAssignmentOptimizer (transparent PHP scoring);
 * a future CpSatBedAssignmentOptimizer (Python/OR-Tools service) implements the same
 * contract without changing any caller.
 */
interface BedAssignmentOptimizer
{
    public function recommend(BedRequest $request): RankedRecommendations;
}
