<?php

namespace App\Rtdc\Optimizer;

/** A bed pruned by a hard constraint, with the reason. */
final readonly class ExcludedBed
{
    public function __construct(public int $bedId, public string $reason) {}

    public function toArray(): array
    {
        return ['bed_id' => $this->bedId, 'reason' => $this->reason];
    }
}
