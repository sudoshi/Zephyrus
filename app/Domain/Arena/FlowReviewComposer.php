<?php

namespace App\Domain\Arena;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Zephyrus 2.0 — Part X / Flow Reconciliation. The pure core of the 48-Hour Flow
 * Review: it folds ONE OCPM mining pass (discover + performance + conformance)
 * and the open prod.barriers into ONE ranked artifact under the unified barrier
 * taxonomy — flow (sync-wait), care (conformance deviation), human (an
 * operator-flagged prod.barriers row) — matching arenaReviewResponseSchema.
 *
 * Deliberately DB-free and side-effect-free: the orchestrator (FlowReviewService)
 * gathers the raw inputs and persists the result; everything interesting — which
 * hand-off is a barrier, how severe, what it focuses on the map — happens here so
 * it can be pinned by a unit test without a sidecar or a database.
 *
 * The severity bands are the engine's classification, not a colour the front-end
 * invents: the FE overlay (reviewGraph.ts) just paints whatever severity we set.
 * The thresholds below are the interim operating policy until a per-transition
 * target/baseline table exists — deliberately conservative so only real barriers
 * glow (earned urgency, CLAUDE.md), never an alarm-fatigue wall.
 */
final class FlowReviewComposer
{
    // Flow (sync-wait) severity by median hand-off wait. A hand-off under an hour,
    // or seen too few times to trust, is not a barrier.
    private const FLOW_CRITICAL_SEC = 14400; // 4h

    private const FLOW_WARNING_SEC = 7200;   // 2h

    private const FLOW_WATCH_SEC = 3600;     // 1h

    private const FLOW_MIN_COUNT = 3;

    // Care (conformance) severity by observed pathway conformance rate.
    private const CARE_CRITICAL_RATE = 0.70;

    private const CARE_WARNING_RATE = 0.90;

    // Human (prod.barriers) severity by how long the barrier has stood open — the
    // only ordinal signal the row carries (no severity column on prod.barriers).
    private const HUMAN_CRITICAL_HRS = 48;

    private const HUMAN_WARNING_HRS = 24;

    private const SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'watch' => 2];

    // Where a care/human barrier lives on the discovered map when it has no arc of
    // its own. Curated because a pathway ('sepsis') and a barrier category
    // ('placement') don't name OCDFG nodes; only ids that actually exist in the
    // window's map are lit (guarded below).
    private const PATHWAY_FOCUS = [
        'sepsis' => ['nodes' => ['ed_arrival'], 'edges' => []],
        'surgical_safety' => ['nodes' => ['or_case_start', 'timeout'], 'edges' => []],
    ];

    private const CATEGORY_FOCUS = [
        'placement' => ['nodes' => ['bed_request', 'assign_bed'], 'edges' => ['bed_request assign_bed']],
        'logistical' => ['nodes' => ['transport'], 'edges' => []],
    ];

    /**
     * Build the `available:true` artifact (the orchestrator layers cached/stale on).
     *
     * @param  array<string, mixed>  $discover  sidecar /discover response
     * @param  array<string, mixed>|null  $performance  sidecar /performance response (or null if down)
     * @param  array<int, array<string, mixed>>|null  $conformance  sidecar /conformance list (or null if down)
     * @param  array<int, array<string, mixed>>  $humanBarriers  normalised open prod.barriers rows
     * @param  array<string, mixed>|null  $prior  the previous artifact, for deltas + new-barrier diffing
     * @param  int  $actionsPending  corrective-action drafts awaiting human approval (the P3 plane)
     * @return array<string, mixed>
     */
    public static function compose(
        array $discover,
        ?array $performance,
        ?array $conformance,
        array $humanBarriers,
        ?array $prior,
        int $actionsPending,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $nodeActivity = [];
        $nodeIds = [];
        foreach (($discover['nodes'] ?? []) as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $nodeIds[$id] = true;
            $nodeActivity[$id] = (string) ($node['activity'] ?? $id);
        }
        $edgeSet = [];
        foreach (($discover['edges'] ?? []) as $edge) {
            $edgeSet[self::edgeId((string) ($edge['source'] ?? ''), (string) ($edge['target'] ?? ''))] = true;
        }

        $handoffs = self::normaliseHandoffs($performance['handoffs'] ?? []);
        $priorMedians = self::worstMedianByTransition($prior['performance_index'] ?? []);
        $priorIds = self::priorBarrierIds($prior);
        $priorCareRates = self::priorCareRates($prior);

        $barriers = array_merge(
            self::flowBarriers($handoffs, $priorMedians, $nodeActivity, $nodeIds, $edgeSet),
            self::careBarriers($conformance ?? [], $priorCareRates, $nodeIds, $edgeSet),
            self::humanBarriers($humanBarriers, $to, $nodeIds, $edgeSet),
        );

        usort($barriers, function (array $a, array $b): int {
            $bySeverity = self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']];
            if ($bySeverity !== 0) {
                return $bySeverity;
            }

            return ($b['metric']['value_sec'] ?? 0) <=> ($a['metric']['value_sec'] ?? 0);
        });

        $newCount = 0;
        foreach ($barriers as $barrier) {
            if (! isset($priorIds[$barrier['id']])) {
                $newCount++;
            }
        }

        return [
            'available' => true,
            'window' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'label' => 'Window ending '.$to->format('D d M H:i'),
            ],
            'prior_window_label' => self::priorWindowLabel($prior),
            'generated_at' => $to->toIso8601String(),
            'stats' => self::stats($barriers, $newCount, $actionsPending, $handoffs, $priorMedians, $conformance ?? [], $prior, $nodeActivity),
            'barriers' => array_values($barriers),
            'map' => self::normaliseMap($discover),
            'performance_index' => $handoffs,
        ];
    }

    // --- flow (sync-wait) barriers ---------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $handoffs
     * @param  array<string, float>  $priorMedians
     * @param  array<string, string>  $nodeActivity
     * @param  array<string, bool>  $nodeIds
     * @param  array<string, bool>  $edgeSet
     * @return array<int, array<string, mixed>>
     */
    private static function flowBarriers(array $handoffs, array $priorMedians, array $nodeActivity, array $nodeIds, array $edgeSet): array
    {
        // Fold the per-object-type hand-off rows to the worst (slowest) row per
        // transition — a synchronising hand-off reads as its slowest lifecycle.
        $worst = [];
        foreach ($handoffs as $row) {
            $key = self::edgeId($row['source'], $row['target']);
            if (! isset($worst[$key]) || $row['median_sec'] > $worst[$key]['median_sec']) {
                $worst[$key] = $row;
            }
        }

        $barriers = [];
        foreach ($worst as $key => $row) {
            $severity = self::flowSeverity((float) $row['median_sec'], (int) $row['count']);
            if ($severity === null) {
                continue;
            }

            $source = $row['source'];
            $target = $row['target'];
            $srcLabel = $nodeActivity[$source] ?? $source;
            $tgtLabel = $nodeActivity[$target] ?? $target;
            [$deltaPct, $direction] = self::delta((float) $row['median_sec'], $priorMedians[$key] ?? null);

            $barriers[] = [
                'id' => "flow-{$source}-{$target}",
                'kind' => 'flow',
                'severity' => $severity,
                'title' => "{$srcLabel} → {$tgtLabel} hand-off breaching",
                'subtitle' => 'median '.self::fmtWait((float) $row['median_sec'])." · {$row['count']} waited",
                'location' => ['unit_id' => null, 'unit_label' => null],
                'encounter_ref' => null,
                'opened_at' => null,
                'metric' => [
                    'value_label' => self::fmtWait((float) $row['median_sec']),
                    'value_sec' => (int) round($row['median_sec']),
                    'delta_pct' => $deltaPct,
                    'direction' => $direction,
                ],
                'provenance' => ['source' => 'arena.performance', 'note' => 'sync-wait · observed'],
                'map_focus' => self::focus(
                    [$source, $target],
                    isset($edgeSet[$key]) ? [$key] : [],
                    $nodeIds,
                    $edgeSet,
                ),
            ];
        }

        return $barriers;
    }

    private static function flowSeverity(float $medianSec, int $count): ?string
    {
        if ($count < self::FLOW_MIN_COUNT) {
            return null;
        }
        if ($medianSec >= self::FLOW_CRITICAL_SEC) {
            return 'critical';
        }
        if ($medianSec >= self::FLOW_WARNING_SEC) {
            return 'warning';
        }
        if ($medianSec >= self::FLOW_WATCH_SEC) {
            return 'watch';
        }

        return null;
    }

    // --- care (conformance) barriers -------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $conformance
     * @param  array<string, float>  $priorCareRates
     * @param  array<string, bool>  $nodeIds
     * @param  array<string, bool>  $edgeSet
     * @return array<int, array<string, mixed>>
     */
    private static function careBarriers(array $conformance, array $priorCareRates, array $nodeIds, array $edgeSet): array
    {
        $barriers = [];
        foreach ($conformance as $pathway) {
            $rate = $pathway['conformance_rate'] ?? null;
            $deviant = (int) ($pathway['deviant'] ?? 0);
            if ($rate === null || $deviant <= 0) {
                continue;
            }
            $rate = (float) $rate;
            $severity = self::careSeverity($rate);
            if ($severity === null) {
                continue;
            }

            $slug = (string) ($pathway['pathway'] ?? 'pathway');
            $label = (string) ($pathway['label'] ?? $slug);
            $deviations = array_values($pathway['deviations'] ?? []);
            $topStep = $deviations[0]['code'] ?? null;
            [$deltaPct, $direction] = self::delta($rate, $priorCareRates[$slug] ?? null);

            $focusSpec = self::PATHWAY_FOCUS[$slug] ?? ['nodes' => [], 'edges' => []];

            $barriers[] = [
                'id' => "care-{$slug}",
                'kind' => 'care',
                'severity' => $severity,
                'title' => "{$label} conformance below target",
                'subtitle' => "{$deviant} of {$pathway['cases']} deviated".($topStep ? " · step: {$topStep}" : ''),
                'location' => ['unit_id' => null, 'unit_label' => null],
                'encounter_ref' => null,
                'opened_at' => null,
                'metric' => [
                    'value_label' => round($rate * 100).'%',
                    'value_sec' => null,
                    'delta_pct' => $deltaPct,
                    'direction' => $direction,
                ],
                'provenance' => ['source' => 'arena.conformance', 'note' => 'observed deviation'],
                'map_focus' => self::focus($focusSpec['nodes'], $focusSpec['edges'], $nodeIds, $edgeSet),
                'deviations' => array_map(fn ($d) => [
                    'code' => (string) ($d['code'] ?? ''),
                    'label' => (string) ($d['label'] ?? ''),
                    'count' => (int) ($d['count'] ?? 0),
                ], $deviations),
                'sample_cases' => array_map(fn ($c) => [
                    'case_id' => (string) ($c['case_id'] ?? ''),
                    'deviations' => array_values(array_map('strval', $c['deviations'] ?? [])),
                ], array_values($pathway['sample_deviant_cases'] ?? [])),
            ];
        }

        return $barriers;
    }

    private static function careSeverity(float $rate): ?string
    {
        if ($rate < self::CARE_CRITICAL_RATE) {
            return 'critical';
        }
        if ($rate < self::CARE_WARNING_RATE) {
            return 'warning';
        }
        if ($rate < 1.0) {
            return 'watch';
        }

        return null;
    }

    // --- human (prod.barriers) barriers ----------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, bool>  $nodeIds
     * @param  array<string, bool>  $edgeSet
     * @return array<int, array<string, mixed>>
     */
    private static function humanBarriers(array $rows, CarbonInterface $to, array $nodeIds, array $edgeSet): array
    {
        $barriers = [];
        foreach ($rows as $row) {
            $openedAt = Carbon::parse($row['opened_at']);
            // Age in seconds from timestamps — unambiguous across Carbon versions
            // (diffInHours' sign/return type has drifted between 2.x and 3.x).
            $ageSec = max(0, $to->getTimestamp() - $openedAt->getTimestamp());
            $severity = self::humanSeverity(intdiv($ageSec, 3600));

            $category = (string) ($row['category'] ?? '');
            $unitLabel = $row['unit_label'] ?? null;
            $owner = $row['owner'] ?? null;
            $title = $row['description'] ?: (ucfirst($category ?: 'flagged').' barrier');
            $focusSpec = self::CATEGORY_FOCUS[$category] ?? ['nodes' => [], 'edges' => []];

            $subtitleBits = array_filter([
                $unitLabel ?: 'House',
                $owner ? "by {$owner}" : null,
                'open '.self::fmtWait((float) $ageSec),
            ]);

            $barriers[] = [
                'id' => (string) $row['id'],
                'kind' => 'human',
                'severity' => $severity,
                'title' => $title,
                'subtitle' => implode(' · ', $subtitleBits),
                'location' => [
                    'unit_id' => $row['unit_id'] ?? null,
                    'unit_label' => $unitLabel,
                ],
                // Redacted server-side; lens-aware population is a follow-up, so we
                // never surface a patient ref here.
                'encounter_ref' => null,
                'opened_at' => $openedAt->toIso8601String(),
                'metric' => [
                    'value_label' => 'open '.self::fmtWait((float) $ageSec),
                    'value_sec' => $ageSec,
                    'delta_pct' => null,
                    'direction' => 'flat',
                ],
                'provenance' => ['source' => 'prod.barriers', 'note' => 'open'.($owner ? " · owner: {$owner}" : '')],
                'map_focus' => self::focus($focusSpec['nodes'], $focusSpec['edges'], $nodeIds, $edgeSet),
            ];
        }

        return $barriers;
    }

    private static function humanSeverity(int $ageHrs): string
    {
        if ($ageHrs >= self::HUMAN_CRITICAL_HRS) {
            return 'critical';
        }
        if ($ageHrs >= self::HUMAN_WARNING_HRS) {
            return 'warning';
        }

        return 'watch';
    }

    // --- stats -----------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $barriers
     * @param  array<int, array<string, mixed>>  $handoffs
     * @param  array<string, float>  $priorMedians
     * @param  array<int, array<string, mixed>>  $conformance
     * @param  array<string, mixed>|null  $prior
     * @param  array<string, string>  $nodeActivity
     * @return array<string, mixed>
     */
    private static function stats(array $barriers, int $newCount, int $actionsPending, array $handoffs, array $priorMedians, array $conformance, ?array $prior, array $nodeActivity): array
    {
        // Worst hand-off overall (barrier or not) — the headline sync-wait.
        $worstHandoff = ['label' => 'None', 'value_label' => '—', 'delta_pct' => null];
        $worstMedian = -1.0;
        foreach ($handoffs as $row) {
            if ($row['median_sec'] > $worstMedian) {
                $worstMedian = (float) $row['median_sec'];
                $key = self::edgeId($row['source'], $row['target']);
                [$deltaPct] = self::delta((float) $row['median_sec'], $priorMedians[$key] ?? null);
                $src = $nodeActivity[$row['source']] ?? $row['source'];
                $tgt = $nodeActivity[$row['target']] ?? $row['target'];
                $worstHandoff = [
                    'label' => "{$src} → {$tgt}",
                    'value_label' => self::fmtWait((float) $row['median_sec']),
                    'delta_pct' => $deltaPct,
                ];
            }
        }

        // Worst pathway (lowest observed conformance) — the headline care gap.
        $worstPathway = ['label' => 'None', 'rate' => null, 'delta_pt' => null];
        $worstRate = 2.0;
        $priorWorstRate = self::priorWorstPathwayRate($prior);
        foreach ($conformance as $pathway) {
            $rate = $pathway['conformance_rate'] ?? null;
            if ($rate !== null && (float) $rate < $worstRate) {
                $worstRate = (float) $rate;
                $worstPathway = [
                    'label' => (string) ($pathway['label'] ?? $pathway['pathway'] ?? 'pathway'),
                    'rate' => (float) $rate,
                    'delta_pt' => $priorWorstRate !== null ? (int) round(((float) $rate - $priorWorstRate) * 100) : null,
                ];
            }
        }

        return [
            'open_barriers' => count($barriers),
            'new_barriers' => $newCount,
            // Corrective-action drafts awaiting a human decision on the governed AI
            // plane (propose_pdsa_cycle / propose_pathway_correction). Counted by the
            // orchestrator; on approval the P3 executor materializes the domain row.
            'actions_pending' => $actionsPending,
            'worst_handoff' => $worstHandoff,
            'worst_pathway' => $worstPathway,
        ];
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Keep only ids that exist in the discovered map, so a curated focus never
     * points at a node/arc this window doesn't have.
     *
     * @param  array<int, string>  $nodes
     * @param  array<int, string>  $edges
     * @param  array<string, bool>  $nodeIds
     * @param  array<string, bool>  $edgeSet
     * @return array{node_ids: array<int, string>, edge_ids: array<int, string>}
     */
    private static function focus(array $nodes, array $edges, array $nodeIds, array $edgeSet): array
    {
        return [
            'node_ids' => array_values(array_filter($nodes, fn ($id) => isset($nodeIds[$id]))),
            'edge_ids' => array_values(array_filter($edges, fn ($id) => isset($edgeSet[$id]))),
        ];
    }

    /** Compact wait for a label — mirrors the FE overlay's fmtWait breakpoints. */
    private static function fmtWait(float $seconds): string
    {
        if ($seconds < 5400) {
            return round($seconds / 60).'m';
        }

        return number_format($seconds / 3600, 1).'h';
    }

    /**
     * @return array{0: int|null, 1: string} [delta_pct, direction]
     */
    private static function delta(float $current, ?float $prior): array
    {
        if ($prior === null || $prior <= 0.0) {
            return [null, 'flat'];
        }
        $pct = (int) round((($current - $prior) / $prior) * 100);
        $direction = $current > $prior ? 'up' : ($current < $prior ? 'down' : 'flat');

        return [$pct, $direction];
    }

    private static function edgeId(string $source, string $target): string
    {
        return "{$source} {$target}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function normaliseHandoffs(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! isset($row['source'], $row['target'])) {
                continue;
            }
            $out[] = [
                'object_type' => (string) ($row['object_type'] ?? ''),
                'source' => (string) $row['source'],
                'target' => (string) $row['target'],
                'count' => (int) ($row['count'] ?? 0),
                'median_sec' => (float) ($row['median_sec'] ?? 0),
                'p90_sec' => (float) ($row['p90_sec'] ?? 0),
                'mean_sec' => (float) ($row['mean_sec'] ?? 0),
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $discover @return array<string, mixed> */
    private static function normaliseMap(array $discover): array
    {
        return [
            'object_types' => array_values($discover['object_types'] ?? []),
            'nodes' => array_values($discover['nodes'] ?? []),
            'edges' => array_values($discover['edges'] ?? []),
            'stats' => $discover['stats'] ?? [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $handoffs
     * @return array<string, float>
     */
    private static function worstMedianByTransition(array $handoffs): array
    {
        $out = [];
        foreach ($handoffs as $row) {
            if (! isset($row['source'], $row['target'])) {
                continue;
            }
            $key = self::edgeId((string) $row['source'], (string) $row['target']);
            $median = (float) ($row['median_sec'] ?? 0);
            $out[$key] = isset($out[$key]) ? max($out[$key], $median) : $median;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $prior
     * @return array<string, bool>
     */
    private static function priorBarrierIds(?array $prior): array
    {
        $ids = [];
        foreach (($prior['barriers'] ?? []) as $barrier) {
            if (isset($barrier['id'])) {
                $ids[(string) $barrier['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * Recover prior per-pathway conformance rates from the previous artifact's
     * care barriers (id "care-<slug>", value_label "<n>%") for a points delta.
     *
     * @param  array<string, mixed>|null  $prior
     * @return array<string, float>
     */
    private static function priorCareRates(?array $prior): array
    {
        $rates = [];
        foreach (($prior['barriers'] ?? []) as $barrier) {
            if (($barrier['kind'] ?? null) !== 'care') {
                continue;
            }
            $slug = preg_replace('/^care-/', '', (string) ($barrier['id'] ?? ''));
            $label = (string) ($barrier['metric']['value_label'] ?? '');
            if ($slug !== '' && str_ends_with($label, '%')) {
                $rates[$slug] = ((float) rtrim($label, '%')) / 100;
            }
        }

        return $rates;
    }

    /** @param  array<string, mixed>|null  $prior */
    private static function priorWorstPathwayRate(?array $prior): ?float
    {
        $rate = $prior['stats']['worst_pathway']['rate'] ?? null;

        return $rate === null ? null : (float) $rate;
    }

    /** @param  array<string, mixed>|null  $prior */
    private static function priorWindowLabel(?array $prior): ?string
    {
        $to = $prior['window']['to'] ?? null;

        return $to === null ? null : Carbon::parse($to)->format('D d M H:i');
    }
}
