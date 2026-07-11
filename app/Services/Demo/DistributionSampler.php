<?php

namespace App\Services\Demo;

/**
 * Deterministic weighted sampling for the demo generators (FEEDBACK Wave 2).
 *
 * The operational seeders were coherent but not distribution-true — flat occupancy across
 * every unit type, an ESI-2-heavy ED mix, a ~50% (uniform-hour) discharge-before-noon rate,
 * a STAT-heavy transport queue. This sampler lets the generators draw plausible values from
 * weighted distributions / clinical bands (DistributionProfile) instead of flat constants,
 * while staying fully deterministic (seed in → value out, no global mt_srand state), so
 * repeated demo refreshes remain idempotent.
 */
final class DistributionSampler
{
    /** Deterministic pseudo-random float in [0,1) from an integer seed. */
    public function unit(int $seed): float
    {
        return (crc32('ds:'.$seed) % 1_000_000) / 1_000_000;
    }

    /**
     * Deterministic weighted choice. Returns the KEY of $weights.
     *
     * @template T of array-key
     *
     * @param  array<T,int|float>  $weights  value => weight
     * @return T
     */
    public function weightedPick(array $weights, int $seed): int|string
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_key_first($weights);
        }
        $r = $this->unit($seed) * $total;
        foreach ($weights as $value => $weight) {
            $r -= $weight;
            if ($r < 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    /**
     * Deterministic value uniformly inside a [min,max] band.
     *
     * @param  array{0:float,1:float}  $band
     */
    public function valueInBand(array $band, int $seed): float
    {
        [$min, $max] = $band;

        return $min + $this->unit($seed) * ($max - $min);
    }

    /** Deterministic integer in [min,max] inclusive. */
    public function intBetween(int $min, int $max, int $seed): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (int) floor($this->unit($seed) * ($max - $min + 1));
    }
}
