<?php

namespace App\Rtdc\Optimizer;

final readonly class RankedRecommendations
{
    /**
     * @param  array<int,Recommendation>  $recommendations  ranked desc by score
     * @param  array<int,ExcludedBed>  $excluded
     */
    public function __construct(public array $recommendations, public array $excluded) {}

    public function isEmpty(): bool
    {
        return $this->recommendations === [];
    }

    public function top(): ?Recommendation
    {
        return $this->recommendations[0] ?? null;
    }

    public function runnerUpDelta(): ?int
    {
        if (count($this->recommendations) < 2) {
            return null;
        }

        return $this->recommendations[0]->score - $this->recommendations[1]->score;
    }

    public function toArray(): array
    {
        return [
            'recommendations' => array_map(fn (Recommendation $r) => $r->toArray(), $this->recommendations),
            'runner_up_delta' => $this->runnerUpDelta(),
            'excluded' => array_map(fn (ExcludedBed $e) => $e->toArray(), $this->excluded),
        ];
    }
}
