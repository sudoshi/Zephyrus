<?php

namespace Tests\Unit\Demo;

use App\Services\Demo\DistributionSampler;
use PHPUnit\Framework\TestCase;

class DistributionSamplerTest extends TestCase
{
    public function test_weighted_pick_is_deterministic_for_a_seed(): void
    {
        $s = new DistributionSampler;
        $weights = [1 => 1, 2 => 25, 3 => 46, 4 => 23, 5 => 5];

        $this->assertSame($s->weightedPick($weights, 12345), $s->weightedPick($weights, 12345));
        $this->assertContains($s->weightedPick($weights, 999), array_keys($weights));
    }

    public function test_weighted_pick_roughly_matches_weights_and_keeps_the_mode(): void
    {
        $s = new DistributionSampler;
        $weights = [1 => 1, 2 => 25, 3 => 46, 4 => 23, 5 => 5]; // ESI pyramid, mode = 3
        $counts = array_fill_keys(array_keys($weights), 0);
        for ($seed = 0; $seed < 4000; $seed++) {
            $counts[$s->weightedPick($weights, $seed)]++;
        }

        // ESI-3 must be the modal draw, and rare classes stay rare.
        arsort($counts);
        $this->assertSame(3, array_key_first($counts));
        $this->assertLessThan($counts[2], $counts[1]); // ESI-1 rarer than ESI-2
    }

    public function test_value_in_band_stays_inside_the_band_and_is_deterministic(): void
    {
        $s = new DistributionSampler;
        for ($seed = 0; $seed < 500; $seed++) {
            $v = $s->valueInBand([0.80, 0.96], $seed);
            $this->assertGreaterThanOrEqual(0.80, $v);
            $this->assertLessThanOrEqual(0.96, $v);
        }
        $this->assertSame($s->valueInBand([0.1, 0.2], 7), $s->valueInBand([0.1, 0.2], 7));
    }

    public function test_int_between_is_bounded(): void
    {
        $s = new DistributionSampler;
        for ($seed = 0; $seed < 500; $seed++) {
            $v = $s->intBetween(0, 23, $seed);
            $this->assertGreaterThanOrEqual(0, $v);
            $this->assertLessThanOrEqual(23, $v);
        }
    }
}
