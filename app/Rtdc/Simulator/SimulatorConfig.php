<?php

namespace App\Rtdc\Simulator;

final readonly class SimulatorConfig
{
    public function __construct(
        public int $initialOccupancyPercent,
        public int $admitsPerTick,
        public int $dischargesPerTick,
        public int $ticks,
    ) {}

    public static function default(): self
    {
        return new self(initialOccupancyPercent: 70, admitsPerTick: 3, dischargesPerTick: 2, ticks: 24);
    }
}
