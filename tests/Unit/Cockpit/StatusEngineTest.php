<?php

namespace Tests\Unit\Cockpit;

use App\Enums\CockpitStatus;
use App\Models\Ops\MetricDefinition;
use App\Services\Cockpit\StatusEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit contract tests for the single threshold→status resolver
 * (Zephyrus 2.0 P0, plan §3.3). The canon() map cases mirror
 * tests/js/cockpit/statusStyle.test.ts so the server engine and its client
 * mirror can never diverge. PHPUnit class syntax only (Pest is excluded).
 */
class StatusEngineTest extends TestCase
{
    private StatusEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new StatusEngine;
    }

    /** Unpersisted model — no DB touched; casts operate in memory. */
    private function definition(array $attributes): MetricDefinition
    {
        return new MetricDefinition($attributes);
    }

    // -- direction=down (lower is better; e.g. NEDOCS warn=101 crit=141) ----

    public function test_direction_down_resolves_crit_at_and_above_the_crit_edge(): void
    {
        $def = $this->definition(['direction' => 'down', 'warn_edge' => 101, 'crit_edge' => 141]);

        $this->assertSame(CockpitStatus::CRIT, $this->engine->resolveStatus(142.0, $def));
        $this->assertSame(CockpitStatus::CRIT, $this->engine->resolveStatus(141.0, $def));
    }

    public function test_direction_down_resolves_warn_between_warn_and_crit_edges(): void
    {
        $def = $this->definition(['direction' => 'down', 'warn_edge' => 101, 'crit_edge' => 141]);

        $this->assertSame(CockpitStatus::WARN, $this->engine->resolveStatus(140.9, $def));
        $this->assertSame(CockpitStatus::WARN, $this->engine->resolveStatus(101.0, $def));
    }

    public function test_direction_down_watch_band_fires_within_default_ten_pct_of_warn_edge(): void
    {
        $def = $this->definition(['direction' => 'down', 'warn_edge' => 101, 'crit_edge' => 141]);

        // Band = 10.1 → watch on [90.9, 101).
        $this->assertSame(CockpitStatus::WATCH, $this->engine->resolveStatus(100.0, $def));
        $this->assertSame(CockpitStatus::WATCH, $this->engine->resolveStatus(90.9, $def));
        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(90.8, $def));
    }

    public function test_direction_down_ok_is_rationed_to_definitions_that_set_ok_edge(): void
    {
        $without = $this->definition(['direction' => 'down', 'warn_edge' => 101, 'crit_edge' => 141]);
        $with = $this->definition(['direction' => 'down', 'ok_edge' => 50, 'warn_edge' => 101, 'crit_edge' => 141]);

        // In-band without ok_edge → NORMAL/grey, never teal (decision D3).
        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(60.0, $without));
        $this->assertSame(CockpitStatus::OK, $this->engine->resolveStatus(45.0, $with));
        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(60.0, $with));
    }

    // -- direction=up (higher is better; e.g. dc_before_noon ok=40 warn=40 crit=30)

    public function test_direction_up_resolves_crit_at_and_below_the_crit_edge(): void
    {
        $def = $this->definition(['direction' => 'up', 'ok_edge' => 40, 'warn_edge' => 40, 'crit_edge' => 30]);

        $this->assertSame(CockpitStatus::CRIT, $this->engine->resolveStatus(25.0, $def));
        $this->assertSame(CockpitStatus::CRIT, $this->engine->resolveStatus(30.0, $def));
        $this->assertSame(CockpitStatus::WARN, $this->engine->resolveStatus(35.0, $def));
        $this->assertSame(CockpitStatus::WARN, $this->engine->resolveStatus(40.0, $def));
    }

    public function test_direction_up_an_earned_ok_edge_beats_the_watch_band(): void
    {
        // The catalog seeds ok_edge == warn_edge (e.g. dc_before_noon 40/40/30):
        // anything clear of warn that meets ok_edge is confirmed on-target —
        // ok resolves BEFORE watch (spec §4 order), else green would be
        // unreachable inside the whole watch band.
        $def = $this->definition(['direction' => 'up', 'ok_edge' => 40, 'warn_edge' => 40, 'crit_edge' => 30]);

        $this->assertSame(CockpitStatus::OK, $this->engine->resolveStatus(42.0, $def));
        $this->assertSame(CockpitStatus::OK, $this->engine->resolveStatus(45.0, $def));
    }

    public function test_direction_up_watch_fills_the_gap_below_a_higher_ok_edge(): void
    {
        // ok_edge above warn_edge: the comfort margin between them is where
        // "needs eyes" lives — watch fires there, ok only when comfortably clear.
        $def = $this->definition(['direction' => 'up', 'ok_edge' => 50, 'warn_edge' => 40, 'crit_edge' => 30]);

        $this->assertSame(CockpitStatus::WATCH, $this->engine->resolveStatus(42.0, $def)); // in band (40, 44]
        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(47.0, $def)); // past band, below ok
        $this->assertSame(CockpitStatus::OK, $this->engine->resolveStatus(50.0, $def));
    }

    public function test_watch_band_pct_is_configurable_via_metadata(): void
    {
        $def = $this->definition([
            'direction' => 'down',
            'warn_edge' => 100,
            'crit_edge' => 150,
            'metadata' => ['watch_band_pct' => 20],
        ]);

        $this->assertSame(CockpitStatus::WATCH, $this->engine->resolveStatus(81.0, $def));
        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(79.0, $def));
    }

    // -- degenerate definitions ---------------------------------------------

    public function test_neutral_or_missing_direction_always_resolves_normal(): void
    {
        $neutral = $this->definition(['direction' => 'neutral', 'warn_edge' => 10, 'crit_edge' => 20]);
        $missing = $this->definition([]);

        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(999.0, $neutral));
        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(999.0, $missing));
    }

    public function test_null_edges_are_skipped_not_treated_as_zero(): void
    {
        $warnOnly = $this->definition(['direction' => 'down', 'warn_edge' => 90]);

        $this->assertSame(CockpitStatus::WARN, $this->engine->resolveStatus(95.0, $warnOnly));
        $this->assertSame(CockpitStatus::WATCH, $this->engine->resolveStatus(85.0, $warnOnly));
        $this->assertSame(CockpitStatus::NORMAL, $this->engine->resolveStatus(50.0, $warnOnly));
    }

    // -- canon() reconciliation map (mirrors statusStyle.test.ts) ------------

    public function test_canon_map_is_the_single_bridge_to_the_status_level_vocabulary(): void
    {
        $this->assertSame('neutral', CockpitStatus::NORMAL->canon());
        $this->assertSame('success', CockpitStatus::OK->canon());
        $this->assertSame('info', CockpitStatus::WATCH->canon());
        $this->assertSame('warning', CockpitStatus::WARN->canon());
        $this->assertSame('critical', CockpitStatus::CRIT->canon());
    }

    // -- legacy 3-state parity (behavior-preserving extraction proof) --------

    public function test_high_bad_matches_the_legacy_band_high_bad_contract(): void
    {
        $this->assertSame('critical', $this->engine->highBad(96, 95, 85)->canon());
        $this->assertSame('critical', $this->engine->highBad(95, 95, 85)->canon());
        $this->assertSame('warning', $this->engine->highBad(90, 95, 85)->canon());
        $this->assertSame('warning', $this->engine->highBad(85, 95, 85)->canon());
        $this->assertSame('success', $this->engine->highBad(84.9, 95, 85)->canon());
    }

    public function test_low_bad_matches_the_legacy_band_low_bad_contract(): void
    {
        $this->assertSame('success', $this->engine->lowBad(60, 60, 45)->canon());
        $this->assertSame('warning', $this->engine->lowBad(59.9, 60, 45)->canon());
        $this->assertSame('warning', $this->engine->lowBad(45, 60, 45)->canon());
        $this->assertSame('critical', $this->engine->lowBad(44.9, 60, 45)->canon());
    }

    public function test_progress_matches_the_legacy_band_progress_contract(): void
    {
        $this->assertSame('success', $this->engine->progress(80)->canon());
        $this->assertSame('warning', $this->engine->progress(79)->canon());
        $this->assertSame('warning', $this->engine->progress(40)->canon());
        $this->assertSame('critical', $this->engine->progress(39)->canon());
    }
}
