<?php

namespace Tests\Unit\Rounds;

use App\Services\Rounds\RoundEtaService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoundEtaServiceTest extends TestCase
{
    private RoundEtaService $eta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eta = new RoundEtaService;
    }

    public function test_duration_components_are_transparent_and_sum(): void
    {
        $estimate = $this->eta->estimateDuration([
            'acuity_tier' => 1,
            'missing_required_input' => true,
        ]);

        $codes = array_column($estimate['components'], 'code');
        $this->assertSame(['template_default', 'complexity', 'unresolved_input'], $codes);

        // 8 default + 2 steps * 3 complexity + 4 unresolved = 18
        $this->assertSame(18, $estimate['minutes']);
        $this->assertSame($estimate['minutes'], array_sum(array_column($estimate['components'], 'minutes')));
    }

    public function test_routine_patient_gets_template_default_only(): void
    {
        $estimate = $this->eta->estimateDuration(['acuity_tier' => 3]);

        $this->assertSame(8, $estimate['minutes']);
        $this->assertCount(1, $estimate['components']);
    }

    public function test_template_eta_policy_overrides_defaults(): void
    {
        $estimate = $this->eta->estimateDuration([], ['default_duration_minutes' => 5]);

        $this->assertSame(5, $estimate['minutes']);
    }

    public function test_damping_threshold_suppresses_small_shifts(): void
    {
        $base = Carbon::parse('2026-07-11T10:00:00Z');

        $this->assertFalse($this->eta->shouldNotify($base, $base->copy()->addMinutes(5)));
        $this->assertTrue($this->eta->shouldNotify($base, $base->copy()->addMinutes(15)));
        $this->assertTrue($this->eta->shouldNotify($base, $base->copy()->subMinutes(15)));
        $this->assertFalse($this->eta->shouldNotify(null, $base));
    }
}
