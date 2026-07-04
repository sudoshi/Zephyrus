<?php

namespace Tests\Feature\Cockpit;

use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zephyrus 2.0 P1 — the spec §3.2 sections assembled by the Metrics
 * decomposition: census strip, 9 OKR cards, domains{8} with provenance,
 * derived crit-first alerts, and the D5 hide-demo-domains posture.
 * PHPUnit class syntax only (Pest is excluded on this environment).
 */
class CockpitSnapshotSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SnapshotBuilder::CACHE_KEY);
        $this->seed(CockpitKpiDefinitionSeeder::class);
        $this->seedEdCrowding();
    }

    /**
     * A crowded ED so the LIVE NEDOCS composite (P7 — no longer a demo
     * constant) lands in the severe band and remains the marquee crit alert:
     * 60 patients in a 50-bay ED, 4 ventilated, one 8h admit-hold boarder.
     */
    private function seedEdCrowding(): void
    {
        $cohort = [];
        for ($i = 1; $i <= 60; $i++) {
            $cohort[] = [
                'patient_ref' => sprintf('test-ed-%03d', $i),
                'arrived_at' => now()->subHours(3),
                'esi_level' => $i <= 4 ? 1 : 3,
                'is_ventilated' => $i <= 4,
                'provider_seen_at' => now()->subHours(2),
                'is_deleted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('prod.ed_visits')->insert($cohort);

        // One admitted patient still boarding (8h hold) → the admits + longest
        // admit-hold NEDOCS terms; plus a recently bedded patient for last-bed.
        DB::table('prod.ed_visits')->insert([
            'patient_ref' => 'test-ed-boarder',
            'arrived_at' => now()->subHours(9),
            'esi_level' => 2,
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(8),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('prod.ed_visits')->insert([
            'patient_ref' => 'test-ed-bedded',
            'arrived_at' => now()->subHours(4),
            'esi_level' => 3,
            'disposition' => 'admitted',
            'admit_decision_at' => now()->subHours(3),
            'bed_assigned_at' => now()->subHours(2),
            'departed_at' => now()->subHour(),
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_snapshot_carries_all_spec_sections(): void
    {
        $payload = app(SnapshotBuilder::class)->build();

        $this->assertArrayHasKey('asOf', $payload);
        $this->assertSame('Summit Regional Medical Center', $payload['facility']['name']);
        $this->assertContains($payload['capacityStatus']['code'], ['green', 'yellow', 'red']);
        $this->assertContains($payload['capacityStatus']['status'], ['normal', 'warn', 'crit']);

        // Legacy contract keys coexist untouched (P2 flips the page, not P1).
        $this->assertArrayHasKey('heroMetrics', $payload);
        $this->assertArrayHasKey('strain', $payload);
    }

    public function test_census_strip_and_eight_domains_assemble(): void
    {
        $payload = app(SnapshotBuilder::class)->build();

        $censusKeys = array_column($payload['census'], 'key');
        $this->assertContains('rtdc.occupancy', $censusKeys);
        $this->assertContains('rtdc.boarders', $censusKeys);
        $this->assertGreaterThanOrEqual(7, count($censusKeys));

        $this->assertSame(
            ['rtdc', 'ed', 'periop', 'staffing', 'flow', 'quality', 'service', 'financial'],
            array_keys($payload['domains']),
        );

        $this->assertSame('rtdc.occupancy', $payload['domains']['rtdc']['gaugeKey']);
        $this->assertSame('ed.nedocs', $payload['domains']['ed']['gaugeKey']);
    }

    public function test_okr_scorecard_has_nine_cards_with_objectives_and_owners(): void
    {
        $payload = app(SnapshotBuilder::class)->build();

        $this->assertCount(9, $payload['okrs']);

        $byKey = collect($payload['okrs'])->keyBy('key');
        $dbn = $byKey->get('okr.dc_before_noon');
        $this->assertSame('Smooth Throughput', $dbn['objective']);
        $this->assertSame('CNO', $dbn['owner']);
        $this->assertSame('Discharge before noon', $dbn['keyResult']);
    }

    public function test_mocked_domains_carry_demo_provenance_on_every_tile(): void
    {
        $payload = app(SnapshotBuilder::class)->build();

        foreach (['quality', 'financial'] as $domain) {
            $this->assertSame('demo', $payload['domains'][$domain]['provenance']);

            foreach ($payload['domains'][$domain]['tiles'] as $tile) {
                $this->assertSame('demo', $tile['metadata']['provenance'] ?? null, $tile['key']);
            }
        }

        // Service lines mixes legacy-live O:E/readmit with demo CMI → partial.
        $this->assertSame('partial', $payload['domains']['service']['provenance']);
    }

    public function test_alerts_are_derived_template_gated_and_crit_first(): void
    {
        $payload = app(SnapshotBuilder::class)->build();

        $this->assertNotEmpty($payload['alerts']);

        // The LIVE NEDOCS composite (P7) is the marquee crit — computed from
        // the crowded ED fixture, no longer a demo constant.
        $nedocs = collect($payload['alerts'])->firstWhere('key', 'ed.nedocs');
        $this->assertNotNull($nedocs);
        $this->assertSame('crit', $nedocs['status']);
        $this->assertStringStartsWith('ED OVERCROWDED — NEDOCS ', $nedocs['text']);
        $this->assertNull($nedocs['provenance'] ?? null);

        // crit-first ordering.
        $statuses = array_column($payload['alerts'], 'status');
        $this->assertSame('crit', $statuses[0]);
        $lastCrit = array_search('warn', $statuses, true);
        if ($lastCrit !== false) {
            $this->assertNotContains('crit', array_slice($statuses, $lastCrit));
        }
    }

    public function test_refresh_serves_the_flap_damped_alert_set(): void
    {
        config(['cockpit.alerts.open_holds' => 2, 'cockpit.alerts.min_reconcile_interval' => 0]);

        $builder = app(SnapshotBuilder::class);

        // First snapshot: the NEDOCS candidate is PENDING — the served payload
        // must not strobe it into the ticker yet (P6 flap-damping).
        $first = $builder->refresh();
        $this->assertSame([], $first['alerts']);

        // Second consecutive snapshot: the candidate held → it opens.
        $second = $builder->refresh();
        $nedocs = collect($second['alerts'])->firstWhere('key', 'ed.nedocs');
        $this->assertNotNull($nedocs);
        $this->assertSame('crit', $nedocs['status']);
        $this->assertNull($nedocs['provenance'] ?? null); // live now, not demo
        $this->assertNotNull($nedocs['openedAt'] ?? null);
    }

    public function test_hide_demo_domains_flag_drops_all_demo_domains_only(): void
    {
        config(['cockpit.hide_demo_domains' => true]);

        $payload = app(SnapshotBuilder::class)->build();

        $this->assertArrayNotHasKey('quality', $payload['domains']);
        $this->assertArrayNotHasKey('financial', $payload['domains']);
        // Partial-live domains always stay visible.
        $this->assertArrayHasKey('service', $payload['domains']);
        $this->assertArrayHasKey('rtdc', $payload['domains']);

        // Hidden domains fire no alerts either.
        $alertKeys = array_column($payload['alerts'], 'key');
        foreach ($alertKeys as $key) {
            $this->assertFalse(str_starts_with($key, 'quality.'));
            $this->assertFalse(str_starts_with($key, 'financial.'));
        }
    }

    public function test_metric_status_is_resolved_through_the_seeded_edges(): void
    {
        $payload = app(SnapshotBuilder::class)->build();

        $nedocs = collect($payload['domains']['ed']['tiles'])->firstWhere('key', 'ed.nedocs');
        $this->assertSame('crit', $nedocs['status']); // live composite ≥ crit edge 141

        $handHygiene = collect($payload['domains']['quality']['tiles'])->firstWhere('key', 'quality.hand_hygiene');
        $this->assertSame('ok', $handHygiene['status']); // 91 ≥ ok edge 90 (rationed green)
    }
}
