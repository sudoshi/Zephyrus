<?php

namespace Tests\Feature\Cockpit;

use App\Services\Cockpit\SnapshotBuilder;
use Database\Seeders\CockpitKpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->seedMvFacts();
    }

    /**
     * Minimal Quality/Service/Financial MTD facts + an MV refresh so the P7
     * live domains have data (hand hygiene 91%, sepsis 87%, CMI 1.62, cost
     * $11.9k, and the workforce ratios seeded below).
     */
    private function seedMvFacts(): void
    {
        $events = [];
        foreach (['hand_hygiene' => 91, 'sepsis_3hr' => 87] as $type => $pct) {
            for ($i = 0; $i < 100; $i++) {
                $events[] = [
                    'event_uuid' => (string) Str::uuid(), 'event_type' => $type, 'occurred_at' => now(),
                    'numerator' => $i < $pct ? 1 : 0, 'denominator' => 1, 'patient_days' => 0,
                    'is_deleted' => false, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        DB::table('prod.quality_events')->insert($events);

        $facts = [];
        for ($i = 0; $i < 50; $i++) {
            $facts[] = [
                'fact_uuid' => (string) Str::uuid(), 'patient_ref' => 'test-df-'.$i,
                'drg_weight' => 1.62, 'total_cost' => 11_900, 'is_observation' => $i < 6,
                'discharged_at' => now()->subDays(1), 'is_deleted' => false,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('prod.discharge_facts')->insert($facts);

        $workforce = [];
        foreach (['ICU-A', 'MS-B'] as $cc) {
            $workforce[] = [
                'actual_uuid' => (string) Str::uuid(), 'cost_center' => $cc, 'work_date' => now()->toDateString(),
                'worked_hours' => 250, 'overtime_hours' => 13, 'target_hours' => 242, 'census_days' => 240,
                'premium_cost' => 91_000, 'agency_cost' => 48_000, 'is_deleted' => false,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('prod.workforce_actuals')->insert($workforce);

        foreach (\App\Jobs\RefreshCockpitMaterializedViews::VIEWS as $view) {
            DB::statement("REFRESH MATERIALIZED VIEW {$view}");
        }
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

    public function test_quality_service_financial_are_live_after_p7(): void
    {
        // With the MV facts seeded (setUp), the last three demo domains are now
        // fully live off the materialized views — no tile carries demo
        // provenance, so the domain rolls up as 'live'.
        $payload = app(SnapshotBuilder::class)->build();

        foreach (['quality', 'service', 'financial'] as $domain) {
            $this->assertSame('live', $payload['domains'][$domain]['provenance'], $domain);
            $this->assertNotEmpty($payload['domains'][$domain]['tiles'], $domain);

            foreach ($payload['domains'][$domain]['tiles'] as $tile) {
                $this->assertNull($tile['metadata']['provenance'] ?? null, $tile['key']);
            }
        }

        // The live values match the seeded MV facts.
        $cmi = collect($payload['domains']['service']['tiles'])->firstWhere('key', 'service.cmi');
        $this->assertSame(1.62, $cmi['value']);
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

    public function test_hide_demo_domains_flag_keeps_now_live_domains_visible(): void
    {
        // Post-P7 every operational domain is live, so the D5 hide-demo flag
        // (which only drops all-demo domains) has nothing to hide — the once-
        // mocked Quality/Financial domains stay on the wall.
        config(['cockpit.hide_demo_domains' => true]);

        $payload = app(SnapshotBuilder::class)->build();

        $this->assertArrayHasKey('quality', $payload['domains']);
        $this->assertArrayHasKey('financial', $payload['domains']);
        $this->assertArrayHasKey('service', $payload['domains']);
        $this->assertArrayHasKey('rtdc', $payload['domains']);
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
