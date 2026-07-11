<?php

namespace Tests\Unit\Arena;

use App\Domain\Arena\FlowReviewComposer;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation. The composer is where three
 * unrelated OCPM signals + prod.barriers become ONE ranked barrier list under the
 * unified taxonomy. A wrong severity band, a mis-keyed map_focus, or a bad delta
 * would mis-colour the whole Review map, so the assembly is pinned here — pure,
 * no DB, no sidecar. The severity thresholds ARE the contract the FE overlay
 * paints, so they are asserted by exact band.
 */
class FlowReviewComposerTest extends TestCase
{
    private const TO = '2026-07-10T14:00:00Z'; // a Friday

    /** @return array<string, mixed> */
    private function discover(): array
    {
        return [
            'object_types' => ['Bed', 'Encounter', 'Patient', 'Transport Job'],
            'nodes' => [
                ['id' => 'ed_arrival', 'activity' => 'ED arrival', 'frequency' => 412, 'object_types' => ['Encounter']],
                ['id' => 'direct_add', 'activity' => 'Direct admission', 'frequency' => 96, 'object_types' => ['Encounter']],
                ['id' => 'bed_request', 'activity' => 'Bed request', 'frequency' => 508, 'object_types' => ['Bed']],
                ['id' => 'assign_bed', 'activity' => 'Assign bed', 'frequency' => 471, 'object_types' => ['Bed']],
                ['id' => 'transport', 'activity' => 'Transport', 'frequency' => 433, 'object_types' => ['Transport Job']],
                ['id' => 'occupied', 'activity' => 'Bed occupied', 'frequency' => 455, 'object_types' => ['Bed']],
            ],
            'edges' => [
                ['source' => 'ed_arrival', 'target' => 'bed_request', 'object_type' => 'Encounter', 'frequency' => 392],
                ['source' => 'direct_add', 'target' => 'bed_request', 'object_type' => 'Encounter', 'frequency' => 92],
                ['source' => 'bed_request', 'target' => 'assign_bed', 'object_type' => 'Bed', 'frequency' => 468],
                ['source' => 'assign_bed', 'target' => 'transport', 'object_type' => 'Transport Job', 'frequency' => 431],
                ['source' => 'transport', 'target' => 'occupied', 'object_type' => 'Bed', 'frequency' => 428],
            ],
            'stats' => ['nodes' => 6, 'edges' => 5],
        ];
    }

    /** @return array<string, mixed> */
    private function performance(): array
    {
        return [
            'handoffs' => [
                // critical (4.6h, plenty of volume)
                ['object_type' => 'Transport Job', 'source' => 'assign_bed', 'target' => 'transport', 'count' => 22, 'median_sec' => 16560, 'p90_sec' => 28440, 'mean_sec' => 18120],
                // watch (1.75h)
                ['object_type' => 'Bed', 'source' => 'bed_request', 'target' => 'assign_bed', 'count' => 468, 'median_sec' => 6300, 'p90_sec' => 12600, 'mean_sec' => 7020],
                // watch (exactly 1h floor)
                ['object_type' => 'Encounter', 'source' => 'ed_arrival', 'target' => 'bed_request', 'count' => 392, 'median_sec' => 3600, 'p90_sec' => 8100, 'mean_sec' => 4260],
                // below the watch floor — not a barrier
                ['object_type' => 'Bed', 'source' => 'transport', 'target' => 'occupied', 'count' => 428, 'median_sec' => 1200, 'p90_sec' => 2400, 'mean_sec' => 1380],
                // slow but too few observations — not a barrier
                ['object_type' => 'Encounter', 'source' => 'direct_add', 'target' => 'bed_request', 'count' => 2, 'median_sec' => 15000, 'p90_sec' => 15000, 'mean_sec' => 15000],
            ],
            'synchronization' => [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function conformance(): array
    {
        return [
            [
                'pathway' => 'sepsis', 'label' => 'Sepsis (SEP-3)', 'version' => 3, 'owner' => 'ED', 'case_type' => 'sepsis',
                'cases' => 41, 'conformant' => 35, 'deviant' => 6, 'conformance_rate' => 0.85,
                'deviations' => [
                    ['code' => 'abx_within_3h', 'label' => 'Antibiotic later than 3h', 'count' => 6],
                    ['code' => 'lactate_repeat', 'label' => 'Repeat lactate missing', 'count' => 2],
                ],
                'sample_deviant_cases' => [
                    ['case_id' => 'enc-3d9f21', 'deviations' => ['abx_within_3h']],
                ],
            ],
            // fully conformant — no barrier
            [
                'pathway' => 'surgical_safety', 'label' => 'Surgical safety', 'version' => 1, 'owner' => 'OR', 'case_type' => 'or',
                'cases' => 30, 'conformant' => 30, 'deviant' => 0, 'conformance_rate' => 1.0,
                'deviations' => [], 'sample_deviant_cases' => [],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function humanBarriers(): array
    {
        $to = Carbon::parse(self::TO);

        return [
            ['id' => 'human-77', 'category' => 'placement', 'unit_id' => null, 'unit_label' => 'House', 'owner' => 'C. Ramos', 'description' => 'Isolation bed shortage', 'opened_at' => $to->copy()->subHours(5)->toIso8601String()],
            ['id' => 'human-88', 'category' => 'logistical', 'unit_id' => 41, 'unit_label' => '4 West', 'owner' => 'J. Lee', 'description' => null, 'opened_at' => $to->copy()->subHours(30)->toIso8601String()],
            ['id' => 'human-99', 'category' => 'medical', 'unit_id' => 12, 'unit_label' => 'ED', 'owner' => 'A. Novak', 'description' => 'Awaiting consult', 'opened_at' => $to->copy()->subHours(60)->toIso8601String()],
        ];
    }

    private function compose(?array $prior = null): array
    {
        $to = Carbon::parse(self::TO);

        return FlowReviewComposer::compose(
            $this->discover(),
            $this->performance(),
            $this->conformance(),
            $this->humanBarriers(),
            $prior,
            $to->copy()->subHours(48),
            $to,
        );
    }

    private function barrier(array $artifact, string $id): ?array
    {
        foreach ($artifact['barriers'] as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }

        return null;
    }

    public function test_flow_handoffs_become_severity_banded_barriers(): void
    {
        $out = $this->compose();

        $crit = $this->barrier($out, 'flow-assign_bed-transport');
        $this->assertNotNull($crit);
        $this->assertSame('flow', $crit['kind']);
        $this->assertSame('critical', $crit['severity']);
        $this->assertSame('4.6h', $crit['metric']['value_label']);
        $this->assertSame(16560, $crit['metric']['value_sec']);
        // edge id follows the ocdfgLayout "${src} ${tgt}" convention the FE keys on.
        $this->assertSame(['assign_bed transport'], $crit['map_focus']['edge_ids']);
        $this->assertSame(['assign_bed', 'transport'], $crit['map_focus']['node_ids']);
        $this->assertSame('arena.performance', $crit['provenance']['source']);

        $this->assertSame('watch', $this->barrier($out, 'flow-bed_request-assign_bed')['severity']);
        $this->assertSame('watch', $this->barrier($out, 'flow-ed_arrival-bed_request')['severity']);
    }

    public function test_slow_but_rare_or_fast_handoffs_are_not_barriers(): void
    {
        $out = $this->compose();

        // Below the 1h floor.
        $this->assertNull($this->barrier($out, 'flow-transport-occupied'));
        // Slow (4.2h) but only 2 observations — below FLOW_MIN_COUNT.
        $this->assertNull($this->barrier($out, 'flow-direct_add-bed_request'));
    }

    public function test_conformance_deviation_becomes_a_care_barrier_on_its_node(): void
    {
        $out = $this->compose();

        $care = $this->barrier($out, 'care-sepsis');
        $this->assertNotNull($care);
        $this->assertSame('care', $care['kind']);
        $this->assertSame('warning', $care['severity']); // 0.85 → warning band
        $this->assertSame('85%', $care['metric']['value_label']);
        $this->assertSame(['ed_arrival'], $care['map_focus']['node_ids']);
        $this->assertSame([], $care['map_focus']['edge_ids']);
        $this->assertCount(2, $care['deviations']);
        $this->assertCount(1, $care['sample_cases']);

        // A fully conformant pathway raises no barrier.
        $this->assertNull($this->barrier($out, 'care-surgical_safety'));
    }

    public function test_open_barriers_band_by_age_and_focus_by_category(): void
    {
        $out = $this->compose();

        $this->assertSame('watch', $this->barrier($out, 'human-77')['severity']);   // 5h open
        $this->assertSame('warning', $this->barrier($out, 'human-88')['severity']); // 30h open
        $this->assertSame('critical', $this->barrier($out, 'human-99')['severity']); // 60h open

        // Placement barriers light the bed_request → assign_bed arc.
        $placement = $this->barrier($out, 'human-77');
        $this->assertSame(['bed_request', 'assign_bed'], $placement['map_focus']['node_ids']);
        $this->assertSame(['bed_request assign_bed'], $placement['map_focus']['edge_ids']);
        // A medical barrier has no curated focus → empty (won't mislight the map).
        $this->assertSame([], $this->barrier($out, 'human-99')['map_focus']['node_ids']);

        // A missing description falls back to a category-derived title.
        $this->assertSame('Logistical barrier', $this->barrier($out, 'human-88')['title']);
    }

    public function test_stats_and_shape_without_a_prior(): void
    {
        $out = $this->compose();

        $this->assertTrue($out['available']);
        $this->assertSame('Window ending Fri 10 Jul 14:00', $out['window']['label']);
        $this->assertNull($out['prior_window_label']);

        // 3 flow + 1 care + 3 human = 7 barriers; all new with no prior.
        $this->assertCount(7, $out['barriers']);
        $this->assertSame(7, $out['stats']['open_barriers']);
        $this->assertSame(7, $out['stats']['new_barriers']);
        $this->assertSame(0, $out['stats']['actions_pending']);

        $this->assertSame('Assign bed → Transport', $out['stats']['worst_handoff']['label']);
        $this->assertSame('4.6h', $out['stats']['worst_handoff']['value_label']);
        $this->assertSame('Sepsis (SEP-3)', $out['stats']['worst_pathway']['label']);
        $this->assertSame(0.85, $out['stats']['worst_pathway']['rate']);

        // The worst-first ranking floats a critical to the front.
        $this->assertSame('critical', $out['barriers'][0]['severity']);

        // performance_index is the full hand-off list; map is the discovered graph.
        $this->assertCount(5, $out['performance_index']);
        $this->assertCount(6, $out['map']['nodes']);
    }

    public function test_prior_artifact_drives_deltas_and_new_barrier_count(): void
    {
        $prior = [
            'available' => true,
            'window' => ['from' => '2026-07-06T14:00:00Z', 'to' => '2026-07-08T14:00:00Z', 'label' => 'prior'],
            'stats' => ['worst_pathway' => ['rate' => 0.83]],
            'barriers' => [
                ['id' => 'flow-assign_bed-transport', 'kind' => 'flow', 'metric' => ['value_label' => '3.3h']],
                ['id' => 'care-sepsis', 'kind' => 'care', 'metric' => ['value_label' => '83%']],
            ],
            'performance_index' => [
                ['object_type' => 'Transport Job', 'source' => 'assign_bed', 'target' => 'transport', 'count' => 20, 'median_sec' => 12000, 'p90_sec' => 0, 'mean_sec' => 0],
            ],
        ];

        $out = $this->compose($prior);

        // 16560 vs prior 12000 → +38%, trending up.
        $crit = $this->barrier($out, 'flow-assign_bed-transport');
        $this->assertSame(38, $crit['metric']['delta_pct']);
        $this->assertSame('up', $crit['metric']['direction']);

        // 0.85 vs prior 0.83 → +2% relative, trending up.
        $care = $this->barrier($out, 'care-sepsis');
        $this->assertSame(2, $care['metric']['delta_pct']);
        $this->assertSame('up', $care['metric']['direction']);

        // Two ids carried over from the prior artifact → 5 of 7 are new.
        $this->assertSame(5, $out['stats']['new_barriers']);
        $this->assertSame(2, $out['stats']['worst_pathway']['delta_pt']); // (0.85 - 0.83) * 100
        $this->assertSame('Wed 08 Jul 14:00', $out['prior_window_label']);
    }
}
