<?php

namespace App\Rtdc\Optimizer;

use App\Models\Bed;
use App\Models\BedRequest;
use App\Services\AcuityService;

/** The hard-constraint feasibility check, shared by the optimizer (recommend) and the placement service (accept). */
class BedFeasibility
{
    public function __construct(private readonly AcuityService $acuity) {}

    /** @return string|null the violated hard constraint, or null if the bed is feasible for the request. */
    public function violation(BedRequest $req, Bed $bed): ?string
    {
        $unitType = $bed->unit->type;
        if ($req->required_unit_type !== 'any' && $unitType !== $req->required_unit_type) {
            return "capability: needs {$req->required_unit_type}, bed is {$unitType}";
        }
        if ($req->isolation_required !== 'none' && ! $bed->isolation_capable) {
            return "isolation: needs {$req->isolation_required}, bed not isolation-capable";
        }
        if (! $this->acuity->canAccept($bed->unit_id, $req->acuity_tier)) {
            return 'safety: unit nursing workload cannot safely accept this acuity';
        }

        return null;
    }
}
