<?php

namespace Tests\Feature\Cockpit;

use App\Models\Cockpit\CockpitAlert;
use App\Services\Cockpit\AlertEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P6 — the flap-damped alert lifecycle. The acceptance criteria
 * demand an explicit oscillation test: a metric strobing across its band edge
 * every snapshot must NEVER open an alert (the alarm-fatigue anti-pattern the
 * canon forbids). PHPUnit class syntax only (Pest is excluded here).
 */
class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    private const FACILITY = 'HOSP1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'cockpit.alerts.open_holds' => 2,
            'cockpit.alerts.clear_holds' => 3,
            // Tests drive "snapshots" back-to-back; the burst guard is
            // exercised explicitly in its own tests.
            'cockpit.alerts.min_reconcile_interval' => 0,
        ]);
    }

    /** @param list<array{key: string, status: string, text: string, provenance?: string}> $candidates */
    private function reconcile(array $candidates): array
    {
        return app(AlertEngine::class)->reconcile(self::FACILITY, $candidates);
    }

    private function nedocs(string $status = 'crit', string $text = 'ED OVERCROWDED — NEDOCS 142'): array
    {
        return ['key' => 'ed.nedocs', 'status' => $status, 'text' => $text];
    }

    public function test_alert_opens_only_after_k_consecutive_snapshots(): void
    {
        $this->assertSame([], $this->reconcile([$this->nedocs()]));

        $open = $this->reconcile([$this->nedocs()]);

        $this->assertCount(1, $open);
        $this->assertSame('ed.nedocs', $open[0]['key']);
        $this->assertSame('crit', $open[0]['status']);
        $this->assertNotNull($open[0]['openedAt']);

        $this->assertDatabaseHas('prod.cockpit_alerts', [
            'facility_key' => self::FACILITY,
            'key' => 'ed.nedocs',
            'status' => 'crit',
            'cleared_at' => null,
        ]);
    }

    public function test_oscillating_candidate_never_opens_and_leaves_no_history(): void
    {
        foreach (range(1, 4) as $cycle) {
            $this->assertSame([], $this->reconcile([$this->nedocs()]), "cycle {$cycle}: alerting snapshot");
            $this->assertSame([], $this->reconcile([]), "cycle {$cycle}: normal snapshot");
        }

        // Sub-K flaps never fired, so they leave no rows at all — the alert
        // history (cleared rows) only ever contains alerts that truly opened.
        $this->assertSame(0, CockpitAlert::query()->count());
    }

    public function test_clear_is_damped_and_recandidate_resets_the_clear_hold(): void
    {
        $this->reconcile([$this->nedocs()]);
        $this->reconcile([$this->nedocs()]);

        // Two absent snapshots: held open (clear_holds = 3).
        $this->assertCount(1, $this->reconcile([]));
        $this->assertCount(1, $this->reconcile([]));

        // Candidate returns → the clear hold resets; two more absents still hold.
        $this->reconcile([$this->nedocs()]);
        $this->assertCount(1, $this->reconcile([]));
        $this->assertCount(1, $this->reconcile([]));

        // Third consecutive absent → cleared into history.
        $this->assertSame([], $this->reconcile([]));

        $row = CockpitAlert::query()->where('key', 'ed.nedocs')->sole();
        $this->assertNotNull($row->cleared_at);
    }

    public function test_severity_escalation_updates_the_open_row_in_place(): void
    {
        $this->reconcile([$this->nedocs('warn', 'ED crowded — NEDOCS 112')]);
        $this->reconcile([$this->nedocs('warn', 'ED crowded — NEDOCS 112')]);

        $escalated = $this->reconcile([$this->nedocs('crit', 'ED OVERCROWDED — NEDOCS 145')]);

        $this->assertSame('crit', $escalated[0]['status']);
        $this->assertSame('ED OVERCROWDED — NEDOCS 145', $escalated[0]['text']);
        // In place: no close/reopen, so exactly one lifecycle row exists.
        $this->assertSame(1, CockpitAlert::query()->where('key', 'ed.nedocs')->count());
    }

    public function test_reopen_after_clear_appends_a_new_history_row(): void
    {
        $this->reconcile([$this->nedocs()]);
        $this->reconcile([$this->nedocs()]);
        $this->reconcile([]);
        $this->reconcile([]);
        $this->reconcile([]);

        $this->reconcile([$this->nedocs()]);
        $reopened = $this->reconcile([$this->nedocs()]);

        $this->assertCount(1, $reopened);
        $this->assertSame(2, CockpitAlert::query()->where('key', 'ed.nedocs')->count());
        $this->assertSame(1, CockpitAlert::query()->where('key', 'ed.nedocs')->whereNotNull('cleared_at')->count());
    }

    public function test_open_alert_past_ttl_closes_to_history_and_rederives_fresh(): void
    {
        config(['cockpit.alerts.ttl_hours' => 72]);

        $this->reconcile([$this->nedocs()]);
        $this->reconcile([$this->nedocs()]);
        CockpitAlert::query()->where('key', 'ed.nedocs')->update(['opened_at' => now()->subHours(80)]);

        // Still a live candidate — but the ticker must never carry an 80-hour
        // "active" clock: the stale row closes to history this cycle...
        $this->assertSame([], $this->reconcile([$this->nedocs()]));
        $this->assertSame(1, CockpitAlert::query()->where('key', 'ed.nedocs')->whereNotNull('cleared_at')->count());

        // ...and the persisting condition re-raises as a fresh alert.
        $this->reconcile([$this->nedocs()]);
        $reraised = $this->reconcile([$this->nedocs()]);

        $this->assertCount(1, $reraised);
        $this->assertTrue(now()->parse($reraised[0]['openedAt'])->gt(now()->subMinutes(5)));
    }

    public function test_ttl_zero_disables_expiry(): void
    {
        config(['cockpit.alerts.ttl_hours' => 0]);

        $this->reconcile([$this->nedocs()]);
        $this->reconcile([$this->nedocs()]);
        CockpitAlert::query()->where('key', 'ed.nedocs')->update(['opened_at' => now()->subHours(500)]);

        $open = $this->reconcile([$this->nedocs()]);

        $this->assertCount(1, $open);
        $this->assertSame(0, CockpitAlert::query()->where('key', 'ed.nedocs')->whereNotNull('cleared_at')->count());
    }

    public function test_open_set_is_crit_first_and_carries_candidate_provenance(): void
    {
        $warn = ['key' => 'staffing.overtime', 'status' => 'warn', 'text' => 'OT trending high', 'provenance' => 'demo'];

        $this->reconcile([$warn, $this->nedocs()]);
        $open = $this->reconcile([$warn, $this->nedocs()]);

        $this->assertSame(['ed.nedocs', 'staffing.overtime'], array_column($open, 'key'));
        $this->assertSame('demo', $open[1]['provenance'] ?? null);
        $this->assertArrayNotHasKey('provenance', $open[0]);
    }

    public function test_burst_reconciles_inside_the_interval_serve_reads_without_counting_holds(): void
    {
        config(['cockpit.alerts.min_reconcile_interval' => 60]);

        // First reconcile takes the lock and counts; the burst that follows
        // must NOT collapse the damping window into a single instant...
        $this->assertSame([], $this->reconcile([$this->nedocs()]));
        $this->assertSame([], $this->reconcile([$this->nedocs()]));
        $this->assertSame([], $this->reconcile([$this->nedocs()]));

        $this->assertSame(
            1,
            CockpitAlert::query()->where('key', 'ed.nedocs')->sole()->hold_count,
        );

        // ...but once open (next window), burst reads still serve the open set.
        Cache::forget(AlertEngine::RECONCILE_LOCK_KEY);
        $this->assertCount(1, $this->reconcile([$this->nedocs()]));
        $this->assertCount(1, $this->reconcile([$this->nedocs()]));
    }
}
