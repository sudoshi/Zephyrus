<?php

declare(strict_types=1);

namespace Tests\Feature\CarePathways;

use App\Services\CarePathways\FlowPathwayDemoService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The synthetic demo pathway (FLOW-4D plan Phase D5): a length-of-stay-driven
 * projection compiled through the real ReferenceModelCompiler, gated only by the
 * demo flag and never clinical. No database.
 */
final class FlowPathwayDemoServiceTest extends TestCase
{
    public function test_is_null_when_the_demo_flag_is_off(): void
    {
        config(['care-pathways.demo.enabled' => false]);

        $this->assertNull(app(FlowPathwayDemoService::class)->syntheticProgress(2));
    }

    public function test_projects_length_of_stay_driven_progress(): void
    {
        config(['care-pathways.demo.enabled' => true]);

        $progress = app(FlowPathwayDemoService::class)->syntheticProgress(2, CarbonImmutable::parse('2026-07-27T12:00:00Z'));

        $this->assertNotNull($progress);
        $this->assertTrue($progress['demo']);
        $this->assertFalse($progress['clinical_use']);
        $this->assertSame('Demo — not clinical guidance', $progress['notice']);
        $this->assertSame('synthetic_demo', $progress['source']);
        $this->assertSame('demo-heart-failure', $progress['pathway']['key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $progress['pathway']['digest']);

        // Day offsets (max) are [0,1,1,2,3,4]; at LOS day 2 everything before day
        // 2 is complete, day 2 is current, later days are still planned.
        $this->assertSame(
            ['completed', 'completed', 'completed', 'current', 'planned', 'planned'],
            array_column($progress['milestones'], 'status'),
        );
        $this->assertSame(3, $progress['summary']['completed']);
        $this->assertSame(1, $progress['summary']['current']);
        $this->assertSame(2, $progress['summary']['planned']);
        $this->assertSame(6, $progress['summary']['total']);
        $this->assertSame('hf_gdmt', $progress['summary']['current_stable_key']);
        $this->assertSame('3 of 6 milestones complete', $progress['summary']['elements_met_label']);
    }

    public function test_day_zero_marks_only_arrival_current(): void
    {
        config(['care-pathways.demo.enabled' => true]);

        $progress = app(FlowPathwayDemoService::class)->syntheticProgress(0);

        $this->assertSame('current', $progress['milestones'][0]['status']); // hf_arrival, day 0
        $this->assertSame('hf_arrival', $progress['summary']['current_stable_key']);
        $this->assertSame(0, $progress['summary']['completed']);
    }
}
