<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Builds synthetic, drillable Command Center detail without changing the
 * frozen Inertia dashboard payload emitted by CommandCenterDataService.
 *
 * The current dataset is synthetic by design. It gives the frontend a stable
 * 90-day operational-detail contract while live patient-level feeds are built.
 */
class CommandCenterDrilldownService
{
    private const MIN_DRILL_DAYS = 90;

    private const MAX_DRILL_DAYS = 180;

    public function __construct(
        private readonly CommandCenterDataService $dashboardService,
    ) {}

    /** @return array<string,mixed> */
    public function build(?string $focus = null, int $requestedDays = self::MIN_DRILL_DAYS): array
    {
        $dashboard = $this->dashboardService->build();
        $days = max(self::MIN_DRILL_DAYS, min(self::MAX_DRILL_DAYS, $requestedDays));
        $dates = $this->dates($days);
        $metricSources = $this->metricSources($dashboard);
        $timeline = $this->timeline($dates, $metricSources);
        $units = $this->unitDrilldowns($dashboard, $dates);
        $events = $this->syntheticEvents($dates, $timeline, $dashboard['unitCensus'] ?? []);
        $panels = $this->panelDrilldowns($dashboard, $dates, $metricSources, $timeline);
        $opportunities = $this->opportunities($metricSources, $dashboard);

        return [
            'generatedAtIso' => now()->toIso8601String(),
            'window' => [
                'startDate' => $dates[0],
                'endDate' => $dates[count($dates) - 1],
                'days' => $days,
                'grain' => 'daily',
                'minimumDrillDays' => self::MIN_DRILL_DAYS,
                'synthetic' => true,
            ],
            'focus' => $this->resolveFocus($focus, $metricSources, $dashboard['unitCensus'] ?? []),
            'panels' => $panels,
            'timeline' => $timeline,
            'units' => $units,
            'events' => $events,
            'opportunities' => $opportunities,
            'playbooks' => $this->playbooks(),
            'dataQuality' => [
                'mode' => 'synthetic_operational_detail',
                'clinicalUseNotice' => 'Synthetic drill-down detail for product demonstration and workflow design only; not a source of truth for patient care.',
                'lineage' => [
                    'headlineMetrics' => 'CommandCenterDataService live aggregates',
                    'dailyHistory' => 'Deterministic synthetic extension of 90-day KPI trajectories',
                    'events' => 'Synthetic safety and operations opportunities derived from daily variance patterns',
                ],
            ],
        ];
    }

    /** @return list<string> */
    private function dates(int $days): array
    {
        $start = Carbon::today()->subDays($days - 1);
        $dates = [];

        for ($i = 0; $i < $days; $i++) {
            $dates[] = $start->copy()->addDays($i)->toDateString();
        }

        return $dates;
    }

    /**
     * @param  array<string,mixed>  $dashboard
     * @return list<array{panelKey:string,panelTitle:string,groupKey:?string,groupLabel:?string,metric:array<string,mixed>}>
     */
    private function metricSources(array $dashboard): array
    {
        $sources = [];

        foreach ($dashboard['heroMetrics'] ?? [] as $metric) {
            $sources[] = [
                'panelKey' => 'hero',
                'panelTitle' => 'House Status',
                'groupKey' => 'hero',
                'groupLabel' => 'Command Wall',
                'metric' => $metric,
            ];
        }

        foreach (['capacity', 'flow', 'outcomes', 'forecast'] as $panelKey) {
            if (! isset($dashboard[$panelKey]) || ! is_array($dashboard[$panelKey])) {
                continue;
            }

            $panel = $dashboard[$panelKey];
            foreach ($panel['metrics'] ?? [] as $metric) {
                $sources[] = [
                    'panelKey' => (string) $panel['key'],
                    'panelTitle' => (string) $panel['title'],
                    'groupKey' => null,
                    'groupLabel' => null,
                    'metric' => $metric,
                ];
            }

            foreach ($panel['subgroups'] ?? [] as $group) {
                foreach ($group['metrics'] ?? [] as $metric) {
                    $sources[] = [
                        'panelKey' => (string) $panel['key'],
                        'panelTitle' => (string) $panel['title'],
                        'groupKey' => (string) $group['key'],
                        'groupLabel' => (string) $group['label'],
                        'metric' => $metric,
                    ];
                }
            }
        }

        return $sources;
    }

    /**
     * @param  list<string>  $dates
     * @param  list<array{panelKey:string,panelTitle:string,groupKey:?string,groupLabel:?string,metric:array<string,mixed>}>  $metricSources
     * @return list<array<string,mixed>>
     */
    private function timeline(array $dates, array $metricSources): array
    {
        $timeline = [];
        $days = count($dates);

        foreach ($dates as $index => $date) {
            $dailyMetrics = [];

            foreach ($metricSources as $source) {
                $metric = $source['metric'];
                $value = $this->metricValueAt($metric, $index, $days);
                $dailyMetrics[$metric['key']] = [
                    'metricKey' => $metric['key'],
                    'label' => $metric['label'],
                    'panelKey' => $source['panelKey'],
                    'groupKey' => $source['groupKey'],
                    'value' => $value,
                    'display' => $this->displayValue($metric, $value),
                    'target' => $metric['target'],
                    'targetDisplay' => $metric['targetDisplay'],
                    'status' => $this->statusForValue($metric, $value),
                    'varianceToTarget' => $this->varianceToTarget($metric, $value),
                ];
            }

            $drivers = $this->worstDrivers(array_values($dailyMetrics), 4);
            $timeline[] = [
                'date' => $date,
                'detailHref' => "/api/command-center/drilldown?date={$date}",
                'status' => $this->worstStatus(array_column($drivers, 'status')),
                'driverCount' => count(array_filter(
                    $dailyMetrics,
                    fn (array $metric): bool => in_array($metric['status'], ['critical', 'warning'], true)
                )),
                'metrics' => $dailyMetrics,
                'drivers' => $drivers,
                'safetyOpportunityCount' => count(array_filter(
                    $dailyMetrics,
                    fn (array $metric): bool => $this->isSafetyRelevant($metric['metricKey']) && $metric['status'] !== 'success'
                )),
            ];
        }

        return $timeline;
    }

    /**
     * @param  list<string>  $dates
     * @param  list<array{panelKey:string,panelTitle:string,groupKey:?string,groupLabel:?string,metric:array<string,mixed>}>  $metricSources
     * @param  list<array<string,mixed>>  $timeline
     * @return list<array<string,mixed>>
     */
    private function panelDrilldowns(array $dashboard, array $dates, array $metricSources, array $timeline): array
    {
        $panels = [];

        foreach (['capacity', 'flow', 'outcomes', 'forecast'] as $panelKey) {
            $panel = $dashboard[$panelKey] ?? null;
            if (! is_array($panel)) {
                continue;
            }

            $panelMetrics = array_values(array_filter(
                $metricSources,
                fn (array $source): bool => $source['panelKey'] === $panelKey
            ));
            $metricKeys = array_map(fn (array $source): string => $source['metric']['key'], $panelMetrics);

            $daily = array_map(function (array $day) use ($metricKeys, $panelKey): array {
                $metrics = array_intersect_key($day['metrics'], array_flip($metricKeys));
                $statuses = array_map(fn (array $metric): string => $metric['status'], $metrics);

                return [
                    'date' => $day['date'],
                    'panelKey' => $panelKey,
                    'status' => $this->worstStatus($statuses),
                    'metricCount' => count($metrics),
                    'driverCount' => count(array_filter(
                        $metrics,
                        fn (array $metric): bool => in_array($metric['status'], ['critical', 'warning'], true)
                    )),
                    'metrics' => $metrics,
                    'detailHref' => "/api/command-center/drilldown?focus=panel:{$panelKey}&date={$day['date']}",
                ];
            }, $timeline);

            $panels[] = [
                'key' => $panelKey,
                'title' => $panel['title'],
                'summary' => $panel['summary'],
                'drillHref' => $panel['drillHref'],
                'apiDrillHref' => "/api/command-center/drilldown?focus=panel:{$panelKey}",
                'recommendedInteractions' => $this->panelInteractions($panelKey),
                'daily' => $daily,
                'metrics' => array_map(
                    fn (array $source): array => $this->metricDrilldown($source, $dates),
                    $panelMetrics
                ),
            ];
        }

        return $panels;
    }

    /**
     * @param  array{panelKey:string,panelTitle:string,groupKey:?string,groupLabel:?string,metric:array<string,mixed>}  $source
     * @param  list<string>  $dates
     * @return array<string,mixed>
     */
    private function metricDrilldown(array $source, array $dates): array
    {
        $metric = $source['metric'];
        $days = count($dates);
        $history = [];
        $values = [];

        foreach ($dates as $index => $date) {
            $value = $this->metricValueAt($metric, $index, $days);
            $values[] = $value;
            $history[] = [
                'date' => $date,
                'value' => $value,
                'display' => $this->displayValue($metric, $value),
                'status' => $this->statusForValue($metric, $value),
                'varianceToTarget' => $this->varianceToTarget($metric, $value),
                'detailHref' => "/api/command-center/drilldown?focus=metric:{$metric['key']}&date={$date}",
            ];
        }

        return [
            'key' => $metric['key'],
            'label' => $metric['label'],
            'panelKey' => $source['panelKey'],
            'panelTitle' => $source['panelTitle'],
            'groupKey' => $source['groupKey'],
            'groupLabel' => $source['groupLabel'],
            'definition' => $metric['definition'],
            'target' => $metric['target'],
            'targetDisplay' => $metric['targetDisplay'],
            'current' => [
                'value' => $metric['value'],
                'display' => $metric['display'],
                'status' => $metric['status'],
            ],
            'distribution' => $this->distribution($values),
            'history' => $history,
            'recommendedInteractions' => $this->metricInteractions($source['panelKey'], $metric['key']),
        ];
    }

    /**
     * @param  array<string,mixed>  $dashboard
     * @param  list<string>  $dates
     * @return list<array<string,mixed>>
     */
    private function unitDrilldowns(array $dashboard, array $dates): array
    {
        $units = $dashboard['unitCensus'] ?? [];
        if ($units === []) {
            $occupancy = $this->findMetricValue($dashboard['heroMetrics'] ?? [], 'occupancy', 0);
            $available = $this->findMetricValue($dashboard['capacity']['metrics'] ?? [], 'available_beds', 0);
            $units = [[
                'unitId' => 0,
                'name' => 'House aggregate',
                'type' => 'Virtual',
                'staffed' => 100,
                'occupied' => (int) $occupancy,
                'blocked' => 0,
                'available' => (int) $available,
                'occupancyPct' => (int) $occupancy,
                'acuityAdjustedPct' => (int) $occupancy,
                'status' => $this->simpleHighBadStatus((float) $occupancy, 92, 85),
                'syntheticFallback' => true,
            ]];
        }

        return array_map(function (array $unit) use ($dates): array {
            $seed = abs(crc32((string) $unit['name']));
            $history = [];
            $staffed = max(1, (int) $unit['staffed']);

            foreach ($dates as $index => $date) {
                $wave = sin(($index + ($seed % 9)) / 5) * 4 + cos(($index + ($seed % 5)) / 9) * 2;
                $occupancyPct = (int) max(40, min(100, round((float) $unit['occupancyPct'] + $wave)));
                $occupied = (int) min($staffed, round($staffed * $occupancyPct / 100));
                $blocked = (int) max(0, min(8, round((float) $unit['blocked'] + sin(($index + 3) / 8) * 1.5)));
                $available = max(0, $staffed - $occupied - $blocked);
                $acuity = (int) min(100, max(0, $occupancyPct + (($seed + $index) % 7) - 3));

                $history[] = [
                    'date' => $date,
                    'staffed' => $staffed,
                    'occupied' => $occupied,
                    'available' => $available,
                    'blocked' => $blocked,
                    'occupancyPct' => $occupancyPct,
                    'acuityAdjustedPct' => $acuity,
                    'status' => $this->simpleHighBadStatus($occupancyPct, 92, 85),
                    'detailHref' => "/api/command-center/drilldown?focus=unit:{$unit['unitId']}&date={$date}",
                ];
            }

            return [
                'unitId' => $unit['unitId'],
                'name' => $unit['name'],
                'type' => $unit['type'],
                'current' => $unit,
                'history' => $history,
            ];
        }, $units);
    }

    /**
     * @param  list<string>  $dates
     * @param  list<array<string,mixed>>  $timeline
     * @param  list<array<string,mixed>>  $units
     * @return list<array<string,mixed>>
     */
    private function syntheticEvents(array $dates, array $timeline, array $units): array
    {
        $events = [];
        $unitCount = max(1, count($units));

        foreach ($timeline as $index => $day) {
            $driver = $day['drivers'][0] ?? [
                'metricKey' => 'occupancy',
                'label' => 'Occupancy',
                'display' => 'stable',
                'status' => 'success',
                'panelKey' => 'capacity',
            ];
            $unit = $units[$index % $unitCount] ?? ['unitId' => 0, 'name' => 'House aggregate'];
            $severity = $driver['status'] === 'success' ? 'info' : $driver['status'];
            $minutesAtRisk = $severity === 'critical' ? 180 + ($index % 60) : ($severity === 'warning' ? 75 + ($index % 45) : 20 + ($index % 20));

            $events[] = [
                'eventId' => 'sim-'.$dates[$index].'-'.$driver['metricKey'],
                'date' => $dates[$index],
                'timestampIso' => Carbon::parse($dates[$index])->setTime(8 + ($index % 10), ($index * 7) % 60)->toIso8601String(),
                'panelKey' => $driver['panelKey'],
                'metricKey' => $driver['metricKey'],
                'unitId' => $unit['unitId'],
                'unitName' => $unit['name'],
                'severity' => $severity,
                'title' => $this->eventTitle($driver),
                'description' => $this->eventDescription($driver, $unit['name']),
                'recommendedAction' => $this->recommendedAction($driver['metricKey']),
                'timeAtRiskMinutes' => $minutesAtRisk,
                'avoidableBedDays' => round($minutesAtRisk / 1440, 2),
                'patientSafetyDomains' => $this->safetyDomains($driver['metricKey']),
                'synthetic' => true,
            ];
        }

        return $events;
    }

    /**
     * @param  list<array{panelKey:string,panelTitle:string,groupKey:?string,groupLabel:?string,metric:array<string,mixed>}>  $metricSources
     * @param  array<string,mixed>  $dashboard
     * @return list<array<string,mixed>>
     */
    private function opportunities(array $metricSources, array $dashboard): array
    {
        $ranked = [];
        foreach ($metricSources as $source) {
            $metric = $source['metric'];
            $ranked[] = [
                'source' => $source,
                'score' => $this->statusScore((string) $metric['status']) * 100 + abs((float) ($metric['value'] ?? 0)),
            ];
        }
        usort($ranked, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $opportunities = [];
        foreach (array_slice($ranked, 0, 8) as $item) {
            $source = $item['source'];
            $metric = $source['metric'];
            $opportunities[] = [
                'opportunityId' => 'opp-'.$metric['key'],
                'panelKey' => $source['panelKey'],
                'metricKey' => $metric['key'],
                'title' => $this->opportunityTitle($metric),
                'currentSignal' => $metric['display'].' vs '.($metric['targetDisplay'] ?? 'watchlist'),
                'patientSafetySignal' => implode(', ', $this->safetyDomains($metric['key'])),
                'operationalLever' => $this->operationalLever($metric['key']),
                'expectedImpact' => $this->expectedImpact($metric['key']),
                'confidencePct' => max(62, min(94, 94 - ($this->statusScore((string) $metric['status']) * 8))),
                'firstActions' => $this->firstActions($metric['key']),
                'evidenceHref' => "/api/command-center/drilldown?focus=metric:{$metric['key']}",
            ];
        }

        return $opportunities;
    }

    /** @return list<array<string,mixed>> */
    private function playbooks(): array
    {
        return [
            [
                'key' => 'capacity-strain',
                'title' => 'Capacity strain huddle',
                'trigger' => 'Surge level 2 or higher, occupancy above 85%, or projected net beds below zero.',
                'cadenceMinutes' => 120,
                'actions' => [
                    'Confirm unit-level staffed bed accuracy and blocked-bed causes.',
                    'Pull forward discharge-dependent services: pharmacy, transport, environmental services, consult closure.',
                    'Escalate unit-specific bed placement barriers to the accountable leader.',
                ],
            ],
            [
                'key' => 'ed-boarding-safety',
                'title' => 'ED boarding harm-prevention bundle',
                'trigger' => 'Any admitted ED patient waiting for an inpatient bed, with priority above 4 hours.',
                'cadenceMinutes' => 60,
                'actions' => [
                    'Review boarded patient acuity, medication timing, fall risk, and infection-control needs.',
                    'Assign an inpatient-owner check-in for every boarded patient.',
                    'Open an exception path for high-acuity boarders when unit bed readiness stalls.',
                ],
            ],
            [
                'key' => 'or-throughput',
                'title' => 'OR day-of-flow recovery',
                'trigger' => 'First-case on-time below target, turnover above target, or cancellations rising.',
                'cadenceMinutes' => 240,
                'actions' => [
                    'Separate anesthesia, room-readiness, surgeon, and patient-prep delay causes.',
                    'Protect first-case starts by pre-clearing consent, labs, imaging, and implant readiness.',
                    'Identify releaseable blocks and candidate add-on cases before midday.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $metric
     */
    private function metricValueAt(array $metric, int $index, int $days): int|float
    {
        $current = $metric['value'] ?? 0;
        $points = $metric['trajectory']['points'] ?? [];
        $pointCount = count($points);

        if ($pointCount > 0) {
            if ($days <= $pointCount) {
                $offset = $pointCount - $days;

                return $this->normalizeValue((float) $points[$offset + $index], $current);
            }

            $syntheticPrefix = $days - $pointCount;
            if ($index >= $syntheticPrefix) {
                return $this->normalizeValue((float) $points[$index - $syntheticPrefix], $current);
            }

            $first = (float) $points[0];
            $seed = abs(crc32((string) $metric['key']));
            $value = $first + sin(($index + ($seed % 17)) / 4) * max(1, abs($first) * 0.08);

            return $this->normalizeValue($value, $current);
        }

        return $this->normalizeValue((float) $current, $current);
    }

    private function normalizeValue(float $value, mixed $current): int|float
    {
        if (is_int($current)) {
            return (int) round($value);
        }

        return round($value, 1);
    }

    /** @param array<string,mixed> $metric */
    private function displayValue(array $metric, int|float $value): string
    {
        $unit = (string) ($metric['unit'] ?? '');
        $formatted = is_int($value)
            ? (string) $value
            : rtrim(rtrim(number_format($value, 1), '0'), '.');

        return match ($unit) {
            '%' => "{$formatted}%",
            'min' => "{$formatted}m",
            'h' => "{$formatted}h",
            'x' => number_format((float) $value, 2),
            default => $formatted,
        };
    }

    /** @param array<string,mixed> $metric */
    private function statusForValue(array $metric, int|float $value): string
    {
        $key = (string) $metric['key'];
        $target = $metric['target'] ?? null;
        $goodWhenDown = (bool) ($metric['trajectory']['goodWhenDown'] ?? false);

        if (in_array($key, ['net_beds', 'net_beds_fc'], true)) {
            return $value < 0 ? 'critical' : ($value == 0 ? 'warning' : 'success');
        }

        if ($target === null) {
            return (string) ($metric['status'] ?? 'neutral');
        }

        $target = (float) $target;
        if ($target == 0.0) {
            if ($goodWhenDown) {
                return $value <= 0 ? 'success' : ($value <= 3 ? 'warning' : 'critical');
            }

            return $value > 0 ? 'success' : ($value == 0 ? 'warning' : 'critical');
        }

        if ($goodWhenDown) {
            return $value <= $target ? 'success' : ($value <= $target * 1.15 ? 'warning' : 'critical');
        }

        return $value >= $target ? 'success' : ($value >= $target * 0.85 ? 'warning' : 'critical');
    }

    /** @param array<string,mixed> $metric */
    private function varianceToTarget(array $metric, int|float $value): ?float
    {
        if (($metric['target'] ?? null) === null) {
            return null;
        }

        return round((float) $value - (float) $metric['target'], 2);
    }

    /** @param list<int|float> $values */
    private function distribution(array $values): array
    {
        sort($values);
        $count = count($values);

        return [
            'min' => $values[0] ?? 0,
            'p10' => $this->percentile($values, 0.1),
            'median' => $this->percentile($values, 0.5),
            'p90' => $this->percentile($values, 0.9),
            'max' => $values[$count - 1] ?? 0,
        ];
    }

    /** @param list<int|float> $values */
    private function percentile(array $values, float $pct): int|float
    {
        if ($values === []) {
            return 0;
        }

        $index = (int) round((count($values) - 1) * $pct);

        return $values[$index];
    }

    /** @param list<array<string,mixed>> $metrics */
    private function worstDrivers(array $metrics, int $limit): array
    {
        usort($metrics, fn (array $a, array $b): int => $this->statusScore($b['status']) <=> $this->statusScore($a['status']));

        return array_map(fn (array $metric): array => [
            'metricKey' => $metric['metricKey'],
            'panelKey' => $metric['panelKey'],
            'label' => $metric['label'],
            'display' => $metric['display'],
            'status' => $metric['status'],
        ], array_slice($metrics, 0, $limit));
    }

    /** @param list<string> $statuses */
    private function worstStatus(array $statuses): string
    {
        $worst = 'neutral';
        foreach ($statuses as $status) {
            if ($this->statusScore($status) > $this->statusScore($worst)) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private function statusScore(string $status): int
    {
        return match ($status) {
            'critical' => 4,
            'warning' => 3,
            'info' => 2,
            'success' => 1,
            default => 0,
        };
    }

    private function simpleHighBadStatus(float $value, float $critical, float $warning): string
    {
        if ($value >= $critical) {
            return 'critical';
        }

        return $value >= $warning ? 'warning' : 'success';
    }

    /** @param list<array<string,mixed>> $metrics */
    private function findMetricValue(array $metrics, string $key, int|float $default): int|float
    {
        foreach ($metrics as $metric) {
            if (($metric['key'] ?? null) === $key) {
                return $metric['value'] ?? $default;
            }
        }

        return $default;
    }

    private function isSafetyRelevant(string $metricKey): bool
    {
        return in_array($metricKey, [
            'occupancy', 'net_beds', 'ed_boarding', 'blocked_beds', 'ed_d2p',
            'ed_lwbs', 'ed_los', 'adm_to_bed', 'readmission', 'los_gmlos',
            'diversion', 'surge_prob',
        ], true);
    }

    /** @param array<string,mixed> $driver */
    private function eventTitle(array $driver): string
    {
        return match ($driver['metricKey']) {
            'ed_boarding', 'adm_to_bed' => 'Boarding and placement delay detected',
            'blocked_beds' => 'Blocked-bed constraint requires service recovery',
            'ed_lwbs' => 'ED walkout risk is above target',
            'fcots', 'turnover', 'cancellations' => 'OR throughput opportunity detected',
            'readmission', 'los_gmlos', 'excess_days' => 'Quality and length-of-stay opportunity detected',
            'surge_prob', 'net_beds', 'net_beds_fc' => 'Projected bed deficit requires action',
            default => $driver['label'].' variance requires review',
        };
    }

    /** @param array<string,mixed> $driver */
    private function eventDescription(array $driver, string $unitName): string
    {
        return "{$driver['label']} registered {$driver['display']} for {$unitName}; synthetic drill detail highlights the likely operational constraint and first action.";
    }

    private function recommendedAction(string $metricKey): string
    {
        return match ($metricKey) {
            'occupancy', 'available_beds', 'net_beds', 'net_beds_fc', 'surge_prob' => 'Run a capacity huddle, validate staffed beds, and pull forward discharge and transfer constraints.',
            'blocked_beds' => 'Open a barrier review with environmental services, staffing, and isolation owners.',
            'ed_boarding', 'adm_to_bed' => 'Prioritize bed assignment, inpatient acceptance, and safety checks for admitted ED patients.',
            'ed_d2p', 'ed_lwbs', 'ed_los' => 'Rebalance front-end ED resources, fast-track low-acuity flow, and review waiting-room risk.',
            'dbn', 'dc_ready', 'pred_discharges' => 'Activate discharge-before-noon work queue and sequence pharmacy, transport, and final orders.',
            'fcots', 'block_util', 'turnover', 'cancellations' => 'Review OR readiness defects by room, service, surgeon, anesthesia, and pre-op dependency.',
            'readmission', 'los_gmlos', 'excess_days' => 'Review high-risk discharge transitions and excess-day constraints by diagnosis and unit.',
            default => 'Assign an accountable owner, review the event trace, and document the next action before the next huddle.',
        };
    }

    /** @return list<string> */
    private function safetyDomains(string $metricKey): array
    {
        return match ($metricKey) {
            'ed_boarding', 'adm_to_bed', 'ed_los' => ['timely care', 'medication safety', 'fall risk', 'infection prevention'],
            'ed_lwbs', 'ed_d2p' => ['timely care', 'diagnostic delay', 'equity'],
            'occupancy', 'net_beds', 'net_beds_fc', 'surge_prob' => ['timely care', 'workforce safety', 'care reliability'],
            'blocked_beds' => ['infection prevention', 'environment of care', 'timely care'],
            'readmission', 'los_gmlos', 'excess_days' => ['safe transitions', 'care coordination', 'avoidable harm'],
            'fcots', 'turnover', 'cancellations', 'block_util' => ['procedural reliability', 'access', 'handoff safety'],
            default => ['operational reliability'],
        };
    }

    /** @return list<string> */
    private function panelInteractions(string $panelKey): array
    {
        return match ($panelKey) {
            'capacity' => [
                'Click a unit or bed type to open 90-day census, blocked-bed, and acuity detail.',
                'Brush a date range to compare occupancy pressure against discharge readiness.',
                'Toggle bed-class, staffing, isolation, and environmental-services layers.',
            ],
            'flow' => [
                'Switch ED, inpatient, and OR swimlanes without leaving the Command Center.',
                'Click any metric to reveal patient-flow cohorts, delay reasons, and action owners.',
                'Replay a day as an event timeline from arrival, bed request, room readiness, procedure, and discharge.',
            ],
            'outcomes' => [
                'Trace each outcome metric to related capacity and flow precursors over the previous 90 days.',
                'Open safety-opportunity cohorts by unit, service, diagnosis family, and transition point.',
                'Convert a repeated defect pattern directly into a PDSA opportunity.',
            ],
            'forecast' => [
                'Scrub the 24-hour forecast curve to inspect demand, discharges, confidence, and net bed position.',
                'Run what-if adjustments for discharges, staffing, blocked beds, and ED arrival surge.',
                'Compare predicted and actual values by day to expose model drift.',
            ],
            default => [],
        };
    }

    /** @return list<string> */
    private function metricInteractions(string $panelKey, string $metricKey): array
    {
        return [
            "Open 90-day {$metricKey} history with target, variance, and status bands.",
            "Filter by {$panelKey} cohort, unit, service, hour of day, and day of week.",
            'Pin the metric to a huddle board, assign an owner, and attach an improvement action.',
        ];
    }

    /** @param array<string,mixed> $metric */
    private function opportunityTitle(array $metric): string
    {
        return 'Improve '.$metric['label'].' reliability';
    }

    private function operationalLever(string $metricKey): string
    {
        return match ($metricKey) {
            'occupancy', 'net_beds', 'net_beds_fc', 'surge_prob' => 'Demand-capacity balancing',
            'blocked_beds' => 'Barrier removal and bed readiness',
            'ed_boarding', 'adm_to_bed' => 'Placement reliability',
            'ed_lwbs', 'ed_d2p', 'ed_los' => 'ED front-end flow',
            'dbn', 'dc_ready', 'pred_discharges' => 'Discharge orchestration',
            'fcots', 'block_util', 'turnover', 'cancellations' => 'Perioperative reliability',
            'readmission', 'los_gmlos', 'excess_days' => 'Care transitions and progression',
            default => 'Operational reliability',
        };
    }

    private function expectedImpact(string $metricKey): string
    {
        return match ($metricKey) {
            'occupancy', 'net_beds', 'surge_prob' => 'Earlier detection of bed deficits and fewer unsafe boarding hours.',
            'blocked_beds' => 'Recovered staffed capacity and fewer avoidable transfer delays.',
            'ed_boarding', 'adm_to_bed' => 'Reduced ED boarding exposure and faster inpatient ownership.',
            'ed_lwbs', 'ed_d2p' => 'Lower walkout risk and faster first clinical assessment.',
            'dbn', 'dc_ready', 'pred_discharges' => 'More morning capacity and smoother afternoon admission peaks.',
            'fcots', 'turnover', 'block_util' => 'Protected surgical access and fewer downstream bed shocks.',
            'readmission', 'los_gmlos', 'excess_days' => 'Fewer avoidable bed-days and safer transitions of care.',
            default => 'More reliable daily management and clearer accountability.',
        };
    }

    /** @return list<string> */
    private function firstActions(string $metricKey): array
    {
        return [
            $this->recommendedAction($metricKey),
            'Review the last 7 outlier days and tag the most frequent cause family.',
            'Create a same-day owner/action pair and track closure in the next huddle.',
        ];
    }

    /**
     * @param  list<array{panelKey:string,panelTitle:string,groupKey:?string,groupLabel:?string,metric:array<string,mixed>}>  $metricSources
     * @param  list<array<string,mixed>>  $units
     * @return array<string,mixed>
     */
    private function resolveFocus(?string $focus, array $metricSources, array $units): array
    {
        if ($focus === null || $focus === '') {
            return ['type' => 'global', 'key' => 'all', 'label' => 'All Command Center detail', 'matched' => true];
        }

        [$type, $key] = array_pad(explode(':', $focus, 2), 2, null);
        if ($type === 'metric') {
            foreach ($metricSources as $source) {
                if (($source['metric']['key'] ?? null) === $key) {
                    return ['type' => 'metric', 'key' => $key, 'label' => $source['metric']['label'], 'matched' => true];
                }
            }
        }

        if ($type === 'panel' && in_array($key, ['capacity', 'flow', 'outcomes', 'forecast'], true)) {
            return ['type' => 'panel', 'key' => $key, 'label' => ucfirst($key), 'matched' => true];
        }

        if ($type === 'unit') {
            foreach ($units as $unit) {
                if ((string) ($unit['unitId'] ?? '') === (string) $key) {
                    return ['type' => 'unit', 'key' => $key, 'label' => $unit['name'], 'matched' => true];
                }
            }
        }

        return ['type' => $type ?? 'unknown', 'key' => $key ?? $focus, 'label' => $focus, 'matched' => false];
    }
}
