<?php

namespace App\Services\Analytics;

use App\Services\Ops\InterventionAttributionService;
use App\Services\Ops\OperationsRecommendationService;
use App\Services\Ops\OperationsSimulationService;
use App\Services\Transport\TransportOperationsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsAnalyticsService
{
    public function __construct(
        private readonly MetricLineageService $lineage,
        private readonly DataQualityAgentService $dataQualityAgent,
        private readonly OperationsRecommendationService $recommendations,
        private readonly OperationsSimulationService $simulations,
        private readonly InterventionAttributionService $attribution,
    ) {}

    public function overview(): array
    {
        $live = $this->liveSummary();
        $forecast = $this->predictionSummary();
        $quality = $this->dataQualityPayload();
        $sources = $this->sourceMap();
        $sourceDomains = collect($sources)->where('recordCount', '>', 0)->count();
        $trustPct = $quality['summary']['total'] > 0
            ? (int) round($quality['summary']['passing'] / $quality['summary']['total'] * 100)
            : 0;

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'hub',
            'metrics' => [
                $this->metricTile(
                    'Live Signal Coverage',
                    $sourceDomains,
                    'domains',
                    $sourceDomains >= 5 ? 'success' : 'warning',
                    'Operational domains with records currently available to the engine'
                ),
                $this->metricTile(
                    'System Strain',
                    $live['strainScore'],
                    '/100',
                    $this->bandHighBad($live['strainScore'], 75, 50),
                    "{$live['occupancyPct']}% occupancy, {$live['edBoarding']} ED boarders, {$live['pendingAdmits']} pending admits"
                ),
                $this->metricTile(
                    'Net Bed Forecast',
                    $forecast['netBedForecast'],
                    'beds',
                    $forecast['netBedForecast'] < 0 ? 'critical' : ($forecast['netBedForecast'] <= 3 ? 'warning' : 'success'),
                    "{$forecast['demandExpected']} expected demand minus {$forecast['weightedDischarges']} weighted discharges"
                ),
                $this->metricTile(
                    'Data Trust',
                    $trustPct,
                    '%',
                    $trustPct >= 80 ? 'success' : ($trustPct >= 60 ? 'warning' : 'critical'),
                    "{$quality['summary']['passing']} of {$quality['summary']['total']} governance checks passing"
                ),
            ],
            'liveSummary' => $live,
            'predictiveSummary' => $forecast,
            'dataQuality' => $quality,
            'actionQueue' => $this->actionQueue($live, $forecast),
            'sourceMap' => $sources,
        ];
    }

    public function live(): array
    {
        $live = $this->liveSummary();
        $forecast = $this->predictionSummary();

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'live',
            'metrics' => [
                $this->metricTile('House Occupancy', $live['occupancyPct'], '%', $this->bandHighBad($live['occupancyPct'], 92, 85), 'Staffed beds occupied from latest unit census snapshots'),
                $this->metricTile('Net Bed Position', $live['netBeds'], 'beds', $live['netBeds'] < 0 ? 'critical' : ($live['netBeds'] <= 3 ? 'warning' : 'success'), 'Available staffed beds minus pending bed requests'),
                $this->metricTile('ED Boarding', $live['edBoarding'], 'pts', $live['edBoarding'] >= 6 ? 'critical' : ($live['edBoarding'] > 0 ? 'warning' : 'success'), 'Admitted ED patients without an assigned inpatient bed'),
                $this->metricTile('Transport At Risk', $live['transportAtRisk'], 'moves', $live['transportAtRisk'] > 0 ? 'warning' : 'success', 'Active stat or overdue transport requests'),
            ],
            'summary' => $live,
            'units' => $live['units'],
            'actionQueue' => $this->actionQueue($live, $forecast),
            'sourceMap' => $this->sourceMap(),
        ];
    }

    public function retrospective(): array
    {
        $start = Carbon::now()->subDays(30);
        $edTotal = (int) DB::table('prod.ed_visits')
            ->where('arrived_at', '>=', $start)
            ->where('is_deleted', false)
            ->count();
        $lwbs = (int) DB::table('prod.ed_visits')
            ->where('arrived_at', '>=', $start)
            ->where('disposition', 'lwbs')
            ->where('is_deleted', false)
            ->count();
        $lwbsPct = $edTotal > 0 ? round($lwbs / $edTotal * 100, 1) : 0.0;
        $doorToProvider = $this->medianMinutes(
            'SELECT percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM provider_seen_at - arrived_at) / 60
                ) AS med_min
             FROM prod.ed_visits
             WHERE provider_seen_at IS NOT NULL
               AND arrived_at >= ?
               AND is_deleted = false',
            [$start->toDateTimeString()]
        );
        $orCases = (int) DB::table('prod.or_cases')
            ->where('surgery_date', '>=', $start->toDateString())
            ->where('is_deleted', false)
            ->count();
        $blockUtil = (int) round((float) (DB::table('prod.block_utilization')
            ->where('date', '>=', $start->toDateString())
            ->where('is_deleted', false)
            ->avg('utilization_percentage') ?? 0));
        $completedPdsa = (int) DB::table('prod.pdsa_cycles')
            ->where('status', 'completed')
            ->where('completed_at', '>=', $start)
            ->where('is_deleted', false)
            ->count();

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'retrospective',
            'metrics' => [
                $this->metricTile('ED Visits', $edTotal, '30d', 'info', 'Arrival volume in the last 30 days'),
                $this->metricTile('LWBS Rate', $lwbsPct, '%', $this->bandHighBad($lwbsPct, 3, 2), 'Left-without-being-seen rate across the same window'),
                $this->metricTile('Door to Provider', $doorToProvider, 'min', $this->bandHighBad($doorToProvider, 30, 20), 'Median provider delay for visits with complete timestamps'),
                $this->metricTile('Block Utilization', $blockUtil, '%', $blockUtil >= 80 ? 'success' : ($blockUtil >= 70 ? 'warning' : 'critical'), "{$orCases} surgical cases in the 30 day review window"),
            ],
            'trends' => [
                'edArrivalsByWeek' => $this->weeklyCounts('prod.ed_visits', 'arrived_at', $start),
                'orCasesByWeek' => $this->weeklyCounts('prod.or_cases', 'surgery_date', $start),
            ],
            'improvement' => [
                'completedPdsa' => $completedPdsa,
                'activePdsa' => (int) DB::table('prod.pdsa_cycles')
                    ->whereIn('status', ['planned', 'active'])
                    ->where('is_deleted', false)
                    ->count(),
            ],
            'sourceMap' => $this->sourceMap(),
        ];
    }

    public function predictive(): array
    {
        $forecast = $this->predictionSummary();
        $live = $this->liveSummary();
        $surgeStatus = $forecast['bedNeed'] > 8 || $live['occupancyPct'] >= 92
            ? 'critical'
            : ($forecast['bedNeed'] > 0 || $live['occupancyPct'] >= 85 ? 'warning' : 'success');

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'predictive',
            'metrics' => [
                $this->metricTile('Expected Demand', $forecast['demandExpected'], 'beds', $forecast['demandExpected'] >= 20 ? 'warning' : 'info', 'RTDC demand forecast for the selected service date'),
                $this->metricTile('Weighted Discharges', $forecast['weightedDischarges'], 'pts', 'info', 'Clinician-weighted definite, probable, and possible discharges'),
                $this->metricTile('Bed Need', $forecast['bedNeed'], 'beds', $forecast['bedNeed'] > 8 ? 'critical' : ($forecast['bedNeed'] > 0 ? 'warning' : 'success'), 'Positive values indicate demand exceeds expected capacity relief'),
                $this->metricTile('Surge Probability', $forecast['surgeProbability'], '%', $surgeStatus, 'Heuristic warning based on occupancy, bed need, ED boarding, and transport risk'),
            ],
            'forecast' => $forecast,
            'sourceMap' => $this->sourceMap(),
        ];
    }

    public function processIntelligence(): array
    {
        $start = Carbon::now()->subDays(7);
        $operationalEvents = DB::table('prod.operational_events')
            ->where('occurred_at', '>=', $start)
            ->selectRaw('type, COUNT(*) AS total')
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->type,
                'count' => (int) $row->total,
            ])
            ->all();
        $transportEvents = DB::table('prod.transport_events')
            ->where('occurred_at', '>=', $start)
            ->selectRaw('event_type, COUNT(*) AS total')
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'label' => (string) $row->event_type,
                'count' => (int) $row->total,
            ])
            ->all();
        $placementMedian = $this->medianMinutes(
            'SELECT percentile_cont(0.5) WITHIN GROUP (
                    ORDER BY EXTRACT(EPOCH FROM bpd.created_at - br.created_at) / 60
                ) AS med_min
             FROM prod.bed_placement_decisions bpd
             JOIN prod.bed_requests br ON br.bed_request_id = bpd.bed_request_id
             WHERE br.is_deleted = false
               AND bpd.created_at >= ?',
            [$start->toDateTimeString()]
        );
        $eventTotal = array_sum(array_column($operationalEvents, 'count')) + array_sum(array_column($transportEvents, 'count'));
        $openBarriers = $this->openBarrierCount();

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'process-intelligence',
            'metrics' => [
                $this->metricTile('Event Coverage', $eventTotal, '7d events', $eventTotal > 0 ? 'success' : 'warning', 'Canonical operational and transport events available for process mining'),
                $this->metricTile('Placement Cycle', $placementMedian, 'min', $this->bandHighBad($placementMedian, 120, 60), 'Median bed request to placement decision interval'),
                $this->metricTile('Process Variants', count($operationalEvents) + count($transportEvents), 'types', 'info', 'Distinct event types observed in the seven day event log'),
                $this->metricTile('Open Barriers', $openBarriers, 'items', $openBarriers > 0 ? 'warning' : 'success', 'Active blockers available for causal follow-up'),
            ],
            'processes' => [
                ['label' => 'Operational events', 'events' => $operationalEvents],
                ['label' => 'Transport events', 'events' => $transportEvents],
            ],
            'sourceMap' => $this->sourceMap(),
        ];
    }

    public function opportunities(): array
    {
        $live = $this->liveSummary();
        $forecast = $this->predictionSummary();
        $queue = $this->actionQueue($live, $forecast);
        $recommendationPayload = $this->recommendations->generate();
        $graphRecommendations = $recommendationPayload['recommendations'];
        $opportunities = collect($graphRecommendations)
            ->map(fn (array $recommendation): array => [
                'title' => $recommendation['title'],
                'owner' => $recommendation['actions'][0]['payload']['owner'] ?? 'Operations command team',
                'impact' => $this->recommendationImpact($recommendation['riskLevel']),
                'confidence' => $recommendation['confidence'] >= 0.85 ? 'high' : 'medium',
                'score' => $recommendation['score'],
                'route' => $recommendation['actions'][0]['payload']['route'] ?? '/analytics/opportunities',
                'recommendationUuid' => $recommendation['recommendationUuid'],
                'evidence' => $recommendation['evidence'],
                'approvalStatus' => $recommendation['actions'][0]['approvals'][0]['status'] ?? null,
            ])
            ->merge(collect($queue)
                ->map(function (array $item): array {
                    $score = match ($item['status']) {
                        'critical' => 90,
                        'warning' => 70,
                        'success' => 30,
                        default => 50,
                    };

                    return [
                        'title' => $item['title'],
                        'owner' => $item['owner'],
                        'impact' => $item['impact'],
                        'confidence' => $item['status'] === 'success' ? 'medium' : 'high',
                        'score' => $score,
                        'route' => $item['route'],
                    ];
                }))
            ->sortByDesc('score')
            ->values()
            ->all();
        $criticalOpportunityCount = collect($opportunities)->where('score', '>=', 90)->count();
        $openPdsaCount = (int) DB::table('prod.pdsa_cycles')
            ->whereIn('status', ['planned', 'active'])
            ->where('is_deleted', false)
            ->count();
        $unownedBarriers = $this->unownedBarrierCount();

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'opportunities',
            'metrics' => [
                $this->metricTile('Ranked Opportunities', count($opportunities), 'items', count($opportunities) > 0 ? 'info' : 'success', 'Actionable findings with owner, impact, and routing'),
                $this->metricTile('Critical Work', $criticalOpportunityCount, 'items', $criticalOpportunityCount > 0 ? 'critical' : 'success', 'Items that should be reviewed in the next command huddle'),
                $this->metricTile('Open PDSA Cycles', $openPdsaCount, 'cycles', 'info', 'Existing improvement loops that can absorb findings'),
                $this->metricTile('Unowned Barriers', $unownedBarriers, 'items', $unownedBarriers > 0 ? 'warning' : 'success', 'Open barriers missing accountable owner assignment'),
            ],
            'opportunities' => $opportunities,
            'recommendations' => $graphRecommendations,
            'recommendationSummary' => $recommendationPayload['summary'],
            'actionQueue' => $queue,
            'sourceMap' => $this->sourceMap(),
        ];
    }

    public function workbench(): array
    {
        $simulation = $this->simulations->runCapacityWorkbench();
        $impact = $this->attribution->dashboard();
        $summary = $simulation['summary'];

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'workbench',
            'metrics' => [
                $this->metricTile('Scenario Count', $summary['scenarioCount'], 'plans', 'info', 'Persisted deterministic scenarios calculated from live demand and capacity'),
                $this->metricTile('Current Net Forecast', $summary['currentNetBeds'], 'beds', $summary['currentNetBeds'] < 0 ? 'critical' : ($summary['currentNetBeds'] <= 3 ? 'warning' : 'success'), 'Baseline before scenario intervention'),
                $this->metricTile('Best Net Forecast', $summary['bestNetBeds'], 'beds', $summary['bestNetBeds'] < 0 ? 'warning' : 'success', 'Highest resulting bed position among modeled options'),
                $this->metricTile('Measured Interventions', $impact['summary']['totalInterventions'], 'records', $impact['summary']['totalInterventions'] > 0 ? 'success' : 'info', 'Completed actions and PDSA cycles with attribution windows'),
            ],
            'simulation' => $simulation,
            'scenarios' => $simulation['scenarios'],
            'impact' => $impact,
            'sourceMap' => $this->sourceMap(),
        ];
    }

    public function dataQuality(): array
    {
        $payload = $this->dataQualityPayload();
        $sources = $this->sourceMap();
        $agent = $this->dataQualityAgent->run($payload['checks']);

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'section' => 'data-quality',
            'metrics' => [
                $this->metricTile('Checks Passing', $payload['summary']['passing'], 'checks', $payload['summary']['passing'] === $payload['summary']['total'] ? 'success' : 'warning', "{$payload['summary']['total']} total engine governance checks"),
                $this->metricTile('Warnings', $payload['summary']['warning'], 'checks', $payload['summary']['warning'] > 0 ? 'warning' : 'success', 'Checks needing review before high-stakes decisions'),
                $this->metricTile('Critical Gaps', $payload['summary']['critical'], 'checks', $payload['summary']['critical'] > 0 ? 'critical' : 'success', 'Checks that should suppress or qualify downstream use'),
                $this->metricTile('Source Domains', count($sources), 'domains', 'info', 'Live source groups included in the source map'),
            ],
            'checks' => $payload['checks'],
            'summary' => $payload['summary'],
            'agent' => $agent,
            'sourceMap' => $sources,
        ];
    }

    private function liveSummary(): array
    {
        $capacity = $this->houseCapacity();
        $pendingAdmits = (int) DB::table('prod.bed_requests')
            ->where('status', 'pending')
            ->where('is_deleted', false)
            ->count();
        $edBoarding = (int) DB::table('prod.ed_visits')
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false)
            ->count();
        $activeTransport = $this->activeTransportQuery()->count();
        $transportAtRisk = $this->activeTransportQuery()
            ->where(function ($query): void {
                $query->where('priority', 'stat')
                    ->orWhere('needed_at', '<', now());
            })
            ->count();
        $openBarriers = $this->openBarrierCount();
        $orToday = (int) DB::table('prod.or_cases')
            ->whereDate('surgery_date', Carbon::today())
            ->where('is_deleted', false)
            ->count();
        $netBeds = $capacity['available'] - $pendingAdmits;
        $strainScore = min(100, max(0,
            (int) round(($capacity['occupancyPct'] * 0.55)
                + (min(20, $edBoarding) * 1.5)
                + (min(30, $pendingAdmits) * 0.9)
                + (min(20, $transportAtRisk) * 0.8)
                + (min(20, $openBarriers) * 0.7))
        ));

        return [
            'staffedBeds' => $capacity['staffedBeds'],
            'occupied' => $capacity['occupied'],
            'available' => $capacity['available'],
            'blocked' => $capacity['blocked'],
            'occupancyPct' => $capacity['occupancyPct'],
            'latestCensusAtIso' => $capacity['latestCensusAtIso'],
            'pendingAdmits' => $pendingAdmits,
            'edBoarding' => $edBoarding,
            'netBeds' => $netBeds,
            'activeTransport' => (int) $activeTransport,
            'transportAtRisk' => (int) $transportAtRisk,
            'openBarriers' => $openBarriers,
            'orToday' => $orToday,
            'strainScore' => $strainScore,
            'units' => $capacity['units'],
        ];
    }

    private function predictionSummary(): array
    {
        $today = Carbon::today()->toDateString();
        $row = DB::table('prod.rtdc_predictions')
            ->whereDate('service_date', $today)
            ->where('is_deleted', false)
            ->selectRaw(
                'COALESCE(SUM(discharges_weighted), 0) AS weighted_discharges,
                 COALESCE(SUM(demand_expected), 0) AS demand_expected,
                 COALESCE(SUM(bed_need), 0) AS bed_need,
                 COALESCE(SUM(demand_ed), 0) AS demand_ed,
                 COALESCE(SUM(demand_or), 0) AS demand_or,
                 COALESCE(SUM(demand_transfer), 0) AS demand_transfer,
                 COALESCE(SUM(demand_direct), 0) AS demand_direct,
                 COUNT(*) AS prediction_count'
            )
            ->first();
        $capacity = $this->houseCapacity();
        $weightedDischarges = round((float) ($row->weighted_discharges ?? 0), 1);
        $demandExpected = (int) ($row->demand_expected ?? 0);
        $bedNeed = (int) ($row->bed_need ?? 0);
        $livePressure = min(60, (int) round($capacity['occupancyPct'] * 0.45));
        $needPressure = min(30, max(0, $bedNeed) * 3);
        $boardingPressure = min(10, (int) DB::table('prod.ed_visits')
            ->where('disposition', 'admitted')
            ->whereNull('bed_assigned_at')
            ->where('is_deleted', false)
            ->count());
        $surgeProbability = min(95, $livePressure + $needPressure + $boardingPressure);

        return [
            'serviceDate' => $today,
            'predictionCount' => (int) ($row->prediction_count ?? 0),
            'weightedDischarges' => $weightedDischarges,
            'demandExpected' => $demandExpected,
            'demandSources' => [
                'ed' => (int) ($row->demand_ed ?? 0),
                'or' => (int) ($row->demand_or ?? 0),
                'transfer' => (int) ($row->demand_transfer ?? 0),
                'direct' => (int) ($row->demand_direct ?? 0),
            ],
            'bedNeed' => $bedNeed,
            'netBedForecast' => (int) round($capacity['available'] + $weightedDischarges - $demandExpected),
            'surgeProbability' => $surgeProbability,
        ];
    }

    private function houseCapacity(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON (cs.unit_id)
                cs.unit_id, cs.captured_at, cs.staffed_beds, cs.occupied,
                cs.available, cs.blocked, cs.acuity_adjusted_capacity,
                u.name AS unit_name, u.type AS unit_type, u.abbreviation
             FROM prod.census_snapshots cs
             JOIN prod.units u ON u.unit_id = cs.unit_id
             WHERE u.is_deleted = false
             ORDER BY cs.unit_id, cs.captured_at DESC'
        );
        $staffed = (int) array_sum(array_map(fn ($row): int => (int) $row->staffed_beds, $rows));
        $occupied = (int) array_sum(array_map(fn ($row): int => (int) $row->occupied, $rows));
        $available = (int) array_sum(array_map(fn ($row): int => (int) $row->available, $rows));
        $blocked = (int) array_sum(array_map(fn ($row): int => (int) $row->blocked, $rows));
        $latest = collect($rows)
            ->map(fn ($row): Carbon => Carbon::parse($row->captured_at))
            ->sortDesc()
            ->first();
        $units = array_values(array_map(function ($row): array {
            $staffed = (int) $row->staffed_beds;
            $occupied = (int) $row->occupied;
            $occupancyPct = $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0;

            return [
                'unitId' => (int) $row->unit_id,
                'name' => (string) $row->unit_name,
                'type' => (string) $row->unit_type,
                'staffedBeds' => $staffed,
                'occupied' => $occupied,
                'available' => (int) $row->available,
                'blocked' => (int) $row->blocked,
                'occupancyPct' => $occupancyPct,
                'status' => $this->bandHighBad($occupancyPct, 92, 85),
            ];
        }, $rows));

        return [
            'staffedBeds' => $staffed,
            'occupied' => $occupied,
            'available' => $available,
            'blocked' => $blocked,
            'occupancyPct' => $staffed > 0 ? (int) round($occupied / $staffed * 100) : 0,
            'latestCensusAtIso' => $latest?->toIso8601String(),
            'units' => $units,
        ];
    }

    private function actionQueue(?array $live = null, ?array $forecast = null): array
    {
        $live ??= $this->liveSummary();
        $forecast ??= $this->predictionSummary();
        $queue = [];

        if ($live['edBoarding'] > 0) {
            $queue[] = [
                'title' => "{$live['edBoarding']} admitted ED patients awaiting bed",
                'owner' => 'Capacity huddle',
                'impact' => $live['edBoarding'] >= 6 ? 'High' : 'Medium',
                'status' => $live['edBoarding'] >= 6 ? 'critical' : 'warning',
                'route' => '/dashboard/emergency',
            ];
        }
        if ($forecast['bedNeed'] > 0) {
            $queue[] = [
                'title' => "{$forecast['bedNeed']} bed forecast gap by RTDC",
                'owner' => 'RTDC command team',
                'impact' => $forecast['bedNeed'] > 8 ? 'High' : 'Medium',
                'status' => $forecast['bedNeed'] > 8 ? 'critical' : 'warning',
                'route' => '/rtdc/global-huddle',
            ];
        }
        if ($live['pendingAdmits'] > 0) {
            $queue[] = [
                'title' => "{$live['pendingAdmits']} pending bed requests",
                'owner' => 'Bed placement',
                'impact' => $live['pendingAdmits'] > 10 ? 'High' : 'Medium',
                'status' => $live['pendingAdmits'] > 10 ? 'critical' : 'warning',
                'route' => '/rtdc/bed-placement',
            ];
        }
        if ($live['transportAtRisk'] > 0) {
            $queue[] = [
                'title' => "{$live['transportAtRisk']} transport requests at SLA risk",
                'owner' => 'Transport dispatch',
                'impact' => 'Medium',
                'status' => 'warning',
                'route' => '/transport/dispatch',
            ];
        }
        if ($live['openBarriers'] > 0) {
            $queue[] = [
                'title' => "{$live['openBarriers']} open patient-flow barriers",
                'owner' => 'Unit huddles',
                'impact' => 'Medium',
                'status' => 'warning',
                'route' => '/rtdc/unit-huddle',
            ];
        }
        if ($live['orToday'] > 0) {
            $queue[] = [
                'title' => "{$live['orToday']} OR cases feeding today's demand",
                'owner' => 'Perioperative operations',
                'impact' => 'Medium',
                'status' => 'info',
                'route' => '/analytics/or-utilization',
            ];
        }

        if ($queue === []) {
            $queue[] = [
                'title' => 'No high-risk operating signals detected',
                'owner' => 'Operations command team',
                'impact' => 'Stable',
                'status' => 'success',
                'route' => '/dashboard',
            ];
        }

        return array_slice($queue, 0, 6);
    }

    private function dataQualityPayload(): array
    {
        $latestCensus = $this->latestTimestamp('prod.census_snapshots', 'captured_at');
        $predictionCount = $this->todayCount('prod.rtdc_predictions', 'service_date');
        $edTotal = (int) DB::table('prod.ed_visits')
            ->where('arrived_at', '>=', Carbon::now()->subHours(24))
            ->where('is_deleted', false)
            ->count();
        $edWithProvider = (int) DB::table('prod.ed_visits')
            ->where('arrived_at', '>=', Carbon::now()->subHours(24))
            ->where('is_deleted', false)
            ->whereNotNull('provider_seen_at')
            ->count();
        $edCompleteness = $edTotal > 0 ? (int) round($edWithProvider / $edTotal * 100) : 100;
        $activeTransport = $this->activeTransportQuery()->count();
        $transportWithNeed = $this->activeTransportQuery()->whereNotNull('needed_at')->count();
        $transportCompleteness = $activeTransport > 0 ? (int) round($transportWithNeed / $activeTransport * 100) : 100;
        $latestBlock = $this->latestTimestamp('prod.block_utilization', 'date');
        $activePdsa = (int) DB::table('prod.pdsa_cycles')
            ->whereIn('status', ['planned', 'active'])
            ->where('is_deleted', false)
            ->count();
        $ownedPdsa = (int) DB::table('prod.pdsa_cycles')
            ->whereIn('status', ['planned', 'active'])
            ->whereNotNull('owner')
            ->where('owner', '<>', '')
            ->where('is_deleted', false)
            ->count();
        $pdsaOwnership = $activePdsa > 0 ? (int) round($ownedPdsa / $activePdsa * 100) : 100;
        $latestEvent = $this->latestTimestamp('prod.operational_events', 'occurred_at');

        $checks = [
            $this->qualityCheck('Census freshness', $this->freshnessStatus($latestCensus, 120, 360), $this->freshnessLabel($latestCensus), 'prod.census_snapshots captured_at', 'capacity_census'),
            $this->qualityCheck('RTDC prediction presence', $predictionCount > 0 ? 'success' : 'warning', "{$predictionCount} predictions today", 'prod.rtdc_predictions service_date', 'rtdc_predictions'),
            $this->qualityCheck('ED timestamp completeness', $this->completenessStatus($edCompleteness), "{$edCompleteness}% provider-seen coverage", 'prod.ed_visits provider_seen_at', 'ed_flow'),
            $this->qualityCheck('Transport target completeness', $this->completenessStatus($transportCompleteness), "{$transportCompleteness}% active requests have needed_at", 'prod.transport_requests needed_at', 'transport_operations'),
            $this->qualityCheck('OR utilization recency', $this->freshnessStatus($latestBlock, 60 * 24 * 30, 60 * 24 * 60), $this->freshnessLabel($latestBlock), 'prod.block_utilization date', 'surgical_throughput'),
            $this->qualityCheck('PDSA ownership', $this->completenessStatus($pdsaOwnership), "{$pdsaOwnership}% active cycles have owners", 'prod.pdsa_cycles owner', 'improvement_work'),
            $this->qualityCheck('Event spine freshness', $this->freshnessStatus($latestEvent, 60 * 24, 60 * 24 * 7), $this->freshnessLabel($latestEvent), 'prod.operational_events occurred_at', 'process_events'),
        ];
        $summary = [
            'total' => count($checks),
            'passing' => collect($checks)->where('status', 'success')->count(),
            'warning' => collect($checks)->where('status', 'warning')->count(),
            'critical' => collect($checks)->where('status', 'critical')->count(),
        ];

        $this->lineage->recordDataQualityFindings($checks);

        return [
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    private function sourceMap(): array
    {
        return collect(array_keys($this->lineage->sourceCatalog()))
            ->map(fn (string $sourceKey): array => $this->lineage->freshnessForSource($sourceKey))
            ->values()
            ->all();
    }

    private function metricTile(string $label, int|float|string $value, string $unit, string $status, string $detail): array
    {
        return $this->lineage->enrichMetric([
            'label' => $label,
            'value' => is_float($value) ? rtrim(rtrim(number_format($value, 1), '0'), '.') : (string) $value,
            'unit' => $unit,
            'status' => $status,
            'detail' => $detail,
        ]);
    }

    private function recommendationImpact(string $riskLevel): string
    {
        return match ($riskLevel) {
            'critical', 'high' => 'High',
            'medium' => 'Medium',
            default => 'Low',
        };
    }

    private function qualityCheck(string $label, string $status, string $detail, string $lineage, ?string $sourceKey = null): array
    {
        return [
            'key' => Str::snake($label),
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'lineage' => $lineage,
            'sourceKey' => $sourceKey,
        ];
    }

    private function activeTransportQuery()
    {
        return DB::table('prod.transport_requests')
            ->where('is_deleted', false)
            ->whereIn('status', TransportOperationsService::ACTIVE_STATUSES);
    }

    private function openBarrierCount(): int
    {
        return (int) DB::table('prod.barriers')
            ->where('status', 'open')
            ->where('is_deleted', false)
            ->count();
    }

    private function unownedBarrierCount(): int
    {
        return (int) DB::table('prod.barriers')
            ->where('status', 'open')
            ->where(function ($query): void {
                $query->whereNull('owner')
                    ->orWhere('owner', '');
            })
            ->where('is_deleted', false)
            ->count();
    }

    private function latestTimestamp(string $table, string $column): ?Carbon
    {
        $value = DB::table($table)->max($column);

        return $value ? Carbon::parse($value) : null;
    }

    private function todayCount(string $table, string $dateColumn): int
    {
        return (int) DB::table($table)
            ->whereDate($dateColumn, Carbon::today())
            ->where('is_deleted', false)
            ->count();
    }

    private function freshnessLabel(?Carbon $timestamp): string
    {
        if ($timestamp === null) {
            return 'no records';
        }

        $minutes = max(0, $timestamp->diffInMinutes(now()));
        if ($minutes < 90) {
            return "{$minutes}m ago";
        }
        if ($minutes < 60 * 48) {
            return round($minutes / 60).'h ago';
        }

        return $timestamp->toDateString();
    }

    private function freshnessStatus(?Carbon $timestamp, int $successMinutes, int $warningMinutes): string
    {
        if ($timestamp === null) {
            return 'critical';
        }

        $minutes = $timestamp->diffInMinutes(now());
        if ($minutes <= $successMinutes) {
            return 'success';
        }
        if ($minutes <= $warningMinutes) {
            return 'warning';
        }

        return 'critical';
    }

    private function completenessStatus(int $pct): string
    {
        if ($pct >= 90) {
            return 'success';
        }
        if ($pct >= 75) {
            return 'warning';
        }

        return 'critical';
    }

    private function bandHighBad(float|int $value, float|int $critical, float|int $warning): string
    {
        if ($value >= $critical) {
            return 'critical';
        }
        if ($value >= $warning) {
            return 'warning';
        }

        return 'success';
    }

    private function medianMinutes(string $sql, array $bindings): int
    {
        $row = DB::selectOne($sql, $bindings);

        return (int) round((float) ($row->med_min ?? 0));
    }

    private function weeklyCounts(string $table, string $dateColumn, Carbon $start): array
    {
        return DB::table($table)
            ->where($dateColumn, '>=', $start)
            ->where('is_deleted', false)
            ->selectRaw("DATE_TRUNC('week', {$dateColumn})::date AS period, COUNT(*) AS total")
            ->groupByRaw("DATE_TRUNC('week', {$dateColumn})::date")
            ->orderBy('period')
            ->get()
            ->map(fn ($row): array => [
                'period' => (string) $row->period,
                'total' => (int) $row->total,
            ])
            ->all();
    }
}
