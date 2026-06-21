<?php

namespace App\Rtdc\Optimizer;

/** One ranked bed recommendation with its explanation. */
final readonly class Recommendation
{
    /**
     * @param  array<int,array{term:string,value:int}>  $breakdown
     * @param  array<int,array{label:string,ok:bool}>  $chips
     */
    public function __construct(
        public int $bedId,
        public string $bedLabel,
        public int $unitId,
        public string $unitName,
        public int $score,
        public array $breakdown,
        public array $chips,
    ) {}

    public function toArray(): array
    {
        return [
            'bed_id' => $this->bedId,
            'bed_label' => $this->bedLabel,
            'unit_id' => $this->unitId,
            'unit_name' => $this->unitName,
            'score' => $this->score,
            'breakdown' => $this->breakdown,
            'chips' => $this->chips,
        ];
    }
}
