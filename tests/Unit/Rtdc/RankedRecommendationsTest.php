<?php

namespace Tests\Unit\Rtdc;

use App\Rtdc\Optimizer\ExcludedBed;
use App\Rtdc\Optimizer\RankedRecommendations;
use App\Rtdc\Optimizer\Recommendation;
use Tests\TestCase;

class RankedRecommendationsTest extends TestCase
{
    public function test_top_and_runner_up_delta(): void
    {
        $a = new Recommendation(bedId: 1, bedLabel: '5E-01', unitId: 1, unitName: '5 East', score: 30, breakdown: [], chips: []);
        $b = new Recommendation(bedId: 2, bedLabel: '5E-02', unitId: 1, unitName: '5 East', score: 18, breakdown: [], chips: []);
        $r = new RankedRecommendations(recommendations: [$a, $b], excluded: []);

        $this->assertSame(1, $r->top()->bedId);
        $this->assertSame(12, $r->runnerUpDelta()); // 30 - 18
        $this->assertFalse($r->isEmpty());
    }

    public function test_empty_set(): void
    {
        $r = new RankedRecommendations(recommendations: [], excluded: [new ExcludedBed(3, 'isolation mismatch')]);
        $this->assertTrue($r->isEmpty());
        $this->assertNull($r->top());
        $this->assertNull($r->runnerUpDelta());
        $this->assertCount(1, $r->excluded);
    }
}
