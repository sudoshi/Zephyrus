<?php

namespace App\Services\Analytics;

use App\Models\Ops\DataQualityFinding;
use App\Models\Ops\MetricDefinition;
use App\Models\Ops\MetricLineage;
use App\Models\Ops\SourceFreshness;
use App\Support\Operations\DurationFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MetricLineageService
{
    /** @return array<string,array<string,mixed>> */
    public function sourceCatalog(): array
    {
        return [
            'capacity_census' => [
                'label' => 'Capacity census',
                'source' => 'prod.census_snapshots',
                'scope' => 'staffed capacity, occupancy, blocked beds',
                'route' => '/rtdc/bed-tracking',
                'freshness_column' => 'captured_at',
                'expected_lag_minutes' => 120,
                'warning_lag_minutes' => 360,
            ],
            'rtdc_predictions' => [
                'label' => 'RTDC predictions',
                'source' => 'prod.rtdc_predictions',
                'scope' => 'discharges, demand, capacity now, bed need',
                'route' => '/rtdc/predictions/demand',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 1440,
                'warning_lag_minutes' => 2880,
            ],
            'ed_flow' => [
                'label' => 'ED flow',
                'source' => 'prod.ed_visits',
                'scope' => 'arrival, provider, boarding, disposition, departure',
                'route' => '/dashboard/emergency',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 240,
                'warning_lag_minutes' => 1440,
            ],
            'bed_placement' => [
                'label' => 'Bed placement',
                'source' => 'prod.bed_requests',
                'scope' => 'pending admissions and placement demand',
                'route' => '/rtdc/bed-placement',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 240,
                'warning_lag_minutes' => 1440,
            ],
            'encounters' => [
                'label' => 'Patient encounters',
                'source' => 'prod.encounters',
                'scope' => 'active admissions, discharges, length of stay, and readmissions',
                'route' => '/rtdc/bed-placement',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 240,
                'warning_lag_minutes' => 1440,
            ],
            'surgical_throughput' => [
                'label' => 'Surgical throughput',
                'source' => 'prod.or_cases',
                'scope' => 'daily OR demand and surgical deep dives',
                'route' => '/analytics/or-utilization',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 1440,
                'warning_lag_minutes' => 10080,
            ],
            'block_utilization' => [
                'label' => 'Surgical block utilization',
                'source' => 'prod.block_utilization',
                'scope' => 'block allocation, utilization, and scheduled room performance',
                'route' => '/analytics/block-utilization',
                'freshness_column' => 'date',
                'expected_lag_minutes' => 43200,
                'warning_lag_minutes' => 86400,
            ],
            'transport_operations' => [
                'label' => 'Transport operations',
                'source' => 'prod.transport_requests',
                'scope' => 'active moves, SLA risk, handoffs, vendors',
                'route' => '/transport/analytics',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 240,
                'warning_lag_minutes' => 1440,
            ],
            'transport_events' => [
                'label' => 'Transport events',
                'source' => 'prod.transport_events',
                'scope' => 'transport state changes and handoff events',
                'route' => '/transport/analytics',
                'freshness_column' => 'occurred_at',
                'expected_lag_minutes' => 240,
                'warning_lag_minutes' => 1440,
            ],
            'process_events' => [
                'label' => 'Process events',
                'source' => 'prod.operational_events',
                'scope' => 'canonical event log for process mining',
                'route' => '/analytics/process-intelligence',
                'freshness_column' => 'occurred_at',
                'expected_lag_minutes' => 1440,
                'warning_lag_minutes' => 10080,
            ],
            'improvement_work' => [
                'label' => 'Improvement work',
                'source' => 'prod.pdsa_cycles',
                'scope' => 'experiments, owners, sustainment',
                'route' => '/improvement/pdsa',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 10080,
                'warning_lag_minutes' => 43200,
            ],
            'barriers' => [
                'label' => 'Flow barriers',
                'source' => 'prod.barriers',
                'scope' => 'open blockers, accountable owners, and resolution state',
                'route' => '/rtdc/unit-huddle',
                'freshness_column' => 'updated_at',
                'expected_lag_minutes' => 240,
                'warning_lag_minutes' => 1440,
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function metricCatalog(): array
    {
        return [
            'live_signal_coverage' => $this->metric('Live Signal Coverage', 'hub', 'Count of operational source domains with live records available to analytics.', 'Operations command team', 'domains', 'up', ['capacity_census', 'rtdc_predictions', 'ed_flow', 'bed_placement', 'surgical_throughput', 'transport_operations', 'process_events', 'improvement_work']),
            'system_strain' => $this->metric('System Strain', 'hub', 'Composite pressure score from occupancy, ED boarding, pending admits, transport risk, and flow barriers.', 'Operations command team', '/100', 'down', ['capacity_census', 'ed_flow', 'bed_placement', 'transport_operations', 'barriers']),
            'net_bed_forecast' => $this->metric('Net Bed Forecast', 'predictive', 'Available staffed beds plus weighted discharges minus expected RTDC demand.', 'Capacity management', 'beds', 'up', ['capacity_census', 'rtdc_predictions']),
            'data_trust' => $this->metric('Data Trust', 'data-quality', 'Share of analytics governance checks currently passing.', 'Analytics governance', '%', 'up', array_keys($this->sourceCatalog())),
            'house_occupancy' => $this->metric('House Occupancy', 'live', 'Current occupied staffed bed percentage from latest unit census snapshots.', 'House supervisor', '%', 'down', ['capacity_census']),
            'net_bed_position' => $this->metric('Net Bed Position', 'live', 'Current available staffed beds minus pending bed requests.', 'Bed placement', 'beds', 'up', ['capacity_census', 'bed_placement']),
            'ed_boarding' => $this->metric('ED Boarding', 'live', 'Admitted ED patients who do not yet have an inpatient bed assignment timestamp.', 'ED and capacity huddle', 'pts', 'down', ['ed_flow']),
            'transport_at_risk' => $this->metric('Transport At Risk', 'live', 'Active transport requests with stat priority or overdue need time.', 'Transport dispatch', 'moves', 'down', ['transport_operations']),
            'ed_visits' => $this->metric('ED Visits', 'retrospective', 'ED arrival volume in the 30 day review window.', 'Emergency operations', '30d', 'neutral', ['ed_flow']),
            'lwbs_rate' => $this->metric('LWBS Rate', 'retrospective', 'Left-without-being-seen visits divided by ED visits in the 30 day review window.', 'Emergency operations', '%', 'down', ['ed_flow']),
            'door_to_provider' => $this->metric('Door to Provider', 'retrospective', 'Median elapsed minutes from ED arrival to provider seen timestamp.', 'Emergency operations', 'min', 'down', ['ed_flow']),
            'block_utilization' => $this->metric('Block Utilization', 'retrospective', 'Average block utilization percentage over the review window.', 'Perioperative operations', '%', 'up', ['surgical_throughput']),
            'expected_demand' => $this->metric('Expected Demand', 'predictive', 'RTDC expected demand summed across units for the selected service date.', 'Capacity management', 'beds', 'down', ['rtdc_predictions']),
            'weighted_discharges' => $this->metric('Weighted Discharges', 'predictive', 'Clinician-weighted definite, probable, and possible RTDC discharges.', 'Capacity management', 'pts', 'up', ['rtdc_predictions']),
            'bed_need' => $this->metric('Bed Need', 'predictive', 'RTDC bed need summed across units for the selected service date.', 'Capacity management', 'beds', 'down', ['rtdc_predictions']),
            'surge_probability' => $this->metric('Surge Probability', 'predictive', 'Heuristic surge risk from occupancy, bed need, ED boarding, and transport risk.', 'Capacity management', '%', 'down', ['capacity_census', 'rtdc_predictions', 'ed_flow', 'transport_operations']),
            'event_coverage' => $this->metric('Event Coverage', 'process-intelligence', 'Seven-day process event volume available for mining.', 'Process improvement team', '7d events', 'up', ['process_events', 'transport_events']),
            'placement_cycle' => $this->metric('Placement Cycle', 'process-intelligence', 'Median bed request to placement decision interval.', 'Bed placement', 'min', 'down', ['bed_placement']),
            'process_variants' => $this->metric('Process Variants', 'process-intelligence', 'Distinct operational and transport event types observed in the event log.', 'Process improvement team', 'types', 'neutral', ['process_events', 'transport_events']),
            'open_barriers' => $this->metric('Open Barriers', 'process-intelligence', 'Active patient-flow blockers available for causal follow-up.', 'Unit huddles', 'items', 'down', ['barriers']),
            'ranked_opportunities' => $this->metric('Ranked Opportunities', 'opportunities', 'Actionable findings with owner, impact, and routing.', 'Improvement governance council', 'items', 'neutral', ['capacity_census', 'rtdc_predictions', 'ed_flow', 'bed_placement', 'transport_operations', 'barriers', 'improvement_work']),
            'critical_work' => $this->metric('Critical Work', 'opportunities', 'Critical findings that should be reviewed in the next command huddle.', 'Improvement governance council', 'items', 'down', ['capacity_census', 'rtdc_predictions', 'ed_flow', 'bed_placement', 'transport_operations', 'barriers']),
            'open_pdsa_cycles' => $this->metric('Open PDSA Cycles', 'opportunities', 'Existing improvement loops that can absorb findings.', 'Improvement governance council', 'cycles', 'neutral', ['improvement_work']),
            'unowned_barriers' => $this->metric('Unowned Barriers', 'opportunities', 'Open barriers missing accountable owner assignment.', 'Unit huddles', 'items', 'down', ['barriers']),
            'scenario_count' => $this->metric('Scenario Count', 'workbench', 'Bounded what-if actions calculated from live demand and capacity.', 'Operations planning', 'plans', 'neutral', ['capacity_census', 'rtdc_predictions', 'transport_operations']),
            'current_net_forecast' => $this->metric('Current Net Forecast', 'workbench', 'Baseline bed position before scenario intervention.', 'Operations planning', 'beds', 'up', ['capacity_census', 'rtdc_predictions']),
            'best_net_forecast' => $this->metric('Best Net Forecast', 'workbench', 'Highest resulting bed position among modeled options.', 'Operations planning', 'beds', 'up', ['capacity_census', 'rtdc_predictions']),
            'at_risk_transports' => $this->metric('At-Risk Transports', 'workbench', 'Active transport workload that can affect downstream throughput.', 'Transport dispatch', 'moves', 'down', ['transport_operations']),
            'checks_passing' => $this->metric('Checks Passing', 'data-quality', 'Analytics governance checks currently passing.', 'Analytics governance', 'checks', 'up', array_keys($this->sourceCatalog())),
            'warnings' => $this->metric('Warnings', 'data-quality', 'Governance checks needing review before high-stakes decisions.', 'Analytics governance', 'checks', 'down', array_keys($this->sourceCatalog())),
            'critical_gaps' => $this->metric('Critical Gaps', 'data-quality', 'Governance checks that should suppress or qualify downstream use.', 'Analytics governance', 'checks', 'down', array_keys($this->sourceCatalog())),
            'source_domains' => $this->metric('Source Domains', 'data-quality', 'Live source groups included in the source map.', 'Analytics governance', 'domains', 'up', array_keys($this->sourceCatalog())),

            'occupancy' => $this->metric('Occupancy', 'command-center', 'Staffed beds occupied as a percent of staffed capacity.', 'House supervisor', '%', 'down', ['capacity_census']),
            'net_beds' => $this->metric('Net Bed Position', 'command-center', 'Available staffed beds minus pending placement demand.', 'Bed placement', 'beds', 'up', ['capacity_census', 'bed_placement']),
            'dc_ready' => $this->metric('Discharges Ready', 'command-center', 'Active encounters expected to discharge today and awaiting departure.', 'Capacity management', 'pts', 'up', ['encounters']),
            'available_beds' => $this->metric('Available', 'command-center', 'Staffed, unoccupied, unblocked beds available now.', 'House supervisor', 'beds', 'up', ['capacity_census']),
            'blocked_beds' => $this->metric('Blocked', 'command-center', 'Beds offline due to staffing, environmental, or isolation barriers.', 'House supervisor', 'beds', 'down', ['capacity_census', 'barriers']),
            'acuity_adjusted' => $this->metric('Acuity-Adjusted', 'command-center', 'Unit occupancy normalized against acuity-adjusted capacity.', 'House supervisor', '%', 'down', ['capacity_census']),
            'ed_d2p' => $this->metric('Door-to-Provider', 'command-center', 'Median elapsed minutes from ED arrival to provider seen timestamp.', 'Emergency operations', 'min', 'down', ['ed_flow']),
            'ed_lwbs' => $this->metric('LWBS', 'command-center', 'Left-without-being-seen visits divided by ED arrivals.', 'Emergency operations', '%', 'down', ['ed_flow']),
            'ed_los' => $this->metric('ED LOS', 'command-center', 'Median emergency department length of stay for completed ED visits.', 'Emergency operations', 'min', 'down', ['ed_flow']),
            'adm_to_bed' => $this->metric('Admit->Bed', 'command-center', 'Median elapsed minutes from admission decision to bed assignment.', 'Bed placement', 'min', 'down', ['ed_flow', 'bed_placement']),
            'dbn' => $this->metric('Discharge by Noon', 'command-center', 'Share of completed discharges physically departing before noon.', 'Capacity management', '%', 'up', ['encounters']),
            'fcots' => $this->metric('First-Case On-Time', 'command-center', 'First scheduled OR starts that began within the on-time window.', 'Perioperative operations', '%', 'up', ['surgical_throughput']),
            'turnover' => $this->metric('Turnover', 'command-center', 'Median OR room turnover interval between completed and next started case.', 'Perioperative operations', 'min', 'down', ['surgical_throughput']),
            'cancellations' => $this->metric('Cancellations', 'command-center', 'Cancelled surgical cases in the review window.', 'Perioperative operations', 'cases', 'down', ['surgical_throughput']),
            'readmission' => $this->metric('30-Day Readmission', 'command-center', 'Discharged encounters with a subsequent admission inside 30 days.', 'Quality and throughput', '%', 'down', ['encounters']),
            'los_gmlos' => $this->metric('LOS / GMLOS', 'command-center', 'Actual length of stay divided by geometric mean length of stay.', 'Quality and throughput', 'x', 'down', ['encounters']),
            'excess_days' => $this->metric('Excess Bed-Days', 'command-center', 'Bed-days above expected GMLOS across completed encounters.', 'Quality and throughput', 'bed-days', 'down', ['encounters']),
            'diversion' => $this->metric('Diversion Hours', 'command-center', 'Operational diversion time detected from ED flow and event records.', 'Emergency operations', 'hours', 'down', ['ed_flow', 'process_events']),
            'pdsa_active' => $this->metric('Active PDSA', 'command-center', 'Active improvement cycles available to absorb operational opportunities.', 'Improvement governance council', 'cycles', 'neutral', ['improvement_work']),
            'pred_discharges' => $this->metric('Discharges 24h', 'command-center', 'RTDC definite plus probable discharges forecast for the next 24 hours.', 'Capacity management', 'pts', 'up', ['rtdc_predictions']),
            'pred_arrivals' => $this->metric('ED Arrivals 24h', 'command-center', 'Forecasted ED arrivals for the next 24 hours.', 'Emergency operations', 'pts', 'down', ['rtdc_predictions', 'ed_flow']),
            'net_beds_fc' => $this->metric('Net Beds Forecast', 'command-center', 'Forecasted bed position after predicted discharges and arrivals.', 'Capacity management', 'beds', 'up', ['capacity_census', 'rtdc_predictions']),
            'surge_prob' => $this->metric('Surge Probability', 'command-center', 'Command Center surge probability from forecast pressure and current capacity.', 'Capacity management', '%', 'down', ['capacity_census', 'rtdc_predictions', 'ed_flow']),
        ];
    }

    /** @return array<string,mixed> */
    public function enrichMetric(array $metric): array
    {
        $metricKey = $metric['key'] ?? $this->metricKeyForLabel((string) ($metric['label'] ?? 'unknown_metric'));
        $lineage = $this->lineageForMetric($metricKey);

        return array_merge($metric, [
            'key' => $metricKey,
            'lineageHref' => "/api/analytics/metrics/{$metricKey}/lineage",
            'lineageSummary' => $lineage['lineageSummary'],
            'sourceTrust' => $lineage['sourceTrust'],
        ]);
    }

    /** @return array<string,mixed> */
    public function lineageForMetric(string $metricKey): array
    {
        $metricKey = Str::snake(str_replace('-', '_', $metricKey));
        $catalog = $this->metricCatalog();
        $metric = $catalog[$metricKey] ?? $this->metric(
            label: Str::headline($metricKey),
            domain: 'analytics',
            definition: 'Ad hoc analytics metric without a curated definition.',
            owner: 'Analytics governance',
            unit: null,
            direction: 'neutral',
            sourceKeys: []
        );

        $sources = collect($metric['source_keys'])
            ->map(fn (string $sourceKey): array => $this->freshnessForSource($sourceKey))
            ->values()
            ->all();
        $trust = $this->sourceTrust($sources);
        $steps = $this->lineageSteps($metricKey, $metric);

        $this->materializeMetricCatalog($metricKey, $metric, $steps);

        return [
            'metric' => [
                'key' => $metricKey,
                'label' => $metric['label'],
                'domain' => $metric['domain'],
                'definition' => $metric['definition'],
                'owner' => $metric['owner'],
                'unit' => $metric['unit'],
                'direction' => $metric['direction'],
                'cadence' => $metric['cadence'],
            ],
            'sourceTrust' => $trust,
            'sources' => $sources,
            'lineage' => $steps,
            'lineageSummary' => $this->lineageSummary($sources, $trust),
        ];
    }

    /** @return array<string,mixed> */
    public function freshnessForSource(string $sourceKey): array
    {
        $config = $this->sourceCatalog()[$sourceKey] ?? null;
        if ($config === null) {
            return [
                'sourceKey' => $sourceKey,
                'label' => Str::headline($sourceKey),
                'source' => null,
                'scope' => 'unknown source',
                'route' => null,
                'freshnessColumn' => null,
                'latestObservedAtIso' => null,
                'freshnessLabel' => 'unknown source',
                'expectedLagMinutes' => null,
                'warningLagMinutes' => null,
                'status' => 'critical',
                'recordCount' => 0,
            ];
        }

        $latest = $this->tableExists($config['source'])
            ? $this->latestTimestamp($config['source'], $config['freshness_column'])
            : null;
        $recordCount = $this->tableExists($config['source'])
            ? (int) DB::table($config['source'])->count()
            : 0;
        $status = $recordCount > 0
            ? $this->freshnessStatus($latest, $config['expected_lag_minutes'], $config['warning_lag_minutes'])
            : 'warning';
        $entry = [
            'sourceKey' => $sourceKey,
            'label' => $config['label'],
            'source' => $config['source'],
            'scope' => $config['scope'],
            'route' => $config['route'],
            'freshnessColumn' => $config['freshness_column'],
            'latestObservedAtIso' => $latest?->toIso8601String(),
            'freshnessLabel' => $this->freshnessLabel($latest),
            'expectedLagMinutes' => $config['expected_lag_minutes'],
            'warningLagMinutes' => $config['warning_lag_minutes'],
            'status' => $status,
            'recordCount' => $recordCount,
        ];

        $this->materializeSourceFreshness($entry);

        return $entry;
    }

    /** @param array<int,array<string,mixed>> $checks */
    public function recordDataQualityFindings(array $checks): void
    {
        if (! Schema::hasTable('ops.data_quality_findings')) {
            return;
        }

        foreach ($checks as $check) {
            $checkKey = Str::snake((string) ($check['key'] ?? $check['label']));
            $status = (string) $check['status'];

            if ($status === 'success') {
                DataQualityFinding::query()
                    ->where('check_key', $checkKey)
                    ->whereNull('resolved_at')
                    ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);

                continue;
            }

            $finding = DataQualityFinding::firstOrNew(['check_key' => $checkKey, 'resolved_at' => null]);
            if (! $finding->exists) {
                $finding->finding_uuid = (string) Str::uuid();
                $finding->opened_at = now();
            }

            $finding->fill([
                'check_label' => (string) $check['label'],
                'status' => 'open',
                'severity' => $status,
                'source_key' => $check['sourceKey'] ?? null,
                'detail' => (string) $check['detail'],
                'measured_value' => $check['measuredValue'] ?? null,
                'threshold_value' => $check['thresholdValue'] ?? null,
                'metadata' => ['lineage' => $check['lineage'] ?? null],
            ])->save();
        }
    }

    public function metricKeyForLabel(string $label): string
    {
        foreach ($this->metricCatalog() as $key => $metric) {
            if (strcasecmp($metric['label'], $label) === 0) {
                return $key;
            }
        }

        return Str::snake($label);
    }

    /** @return array<string,mixed> */
    private function metric(
        string $label,
        string $domain,
        string $definition,
        string $owner,
        ?string $unit,
        string $direction,
        array $sourceKeys,
        string $cadence = 'live',
    ): array {
        return compact('label', 'domain', 'definition', 'owner', 'unit', 'direction') + [
            'source_keys' => $sourceKeys,
            'cadence' => $cadence,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function lineageSteps(string $metricKey, array $metric): array
    {
        $steps = [];
        foreach (array_values($metric['source_keys']) as $index => $sourceKey) {
            $source = $this->sourceCatalog()[$sourceKey] ?? null;
            if ($source === null) {
                continue;
            }
            [$schema, $table] = explode('.', $source['source'], 2);

            $steps[] = [
                'step' => ($index + 1) * 10,
                'sourceKey' => $sourceKey,
                'source' => $source['source'],
                'sourceSchema' => $schema,
                'sourceTable' => $table,
                'sourceColumn' => null,
                'freshnessColumn' => $source['freshness_column'],
                'transformName' => "operations_analytics.{$metricKey}",
                'description' => "Reads {$source['label']} for {$metric['label']}.",
                'confidenceWeight' => 1.0,
            ];
        }

        return $steps;
    }

    /** @param array<int,array<string,mixed>> $sources */
    private function sourceTrust(array $sources): array
    {
        if ($sources === []) {
            return [
                'score' => 0,
                'status' => 'warning',
                'freshSourceCount' => 0,
                'staleSourceCount' => 0,
                'missingSourceCount' => 0,
            ];
        }

        $score = 0;
        $fresh = 0;
        $stale = 0;
        $missing = 0;
        foreach ($sources as $source) {
            $score += match ($source['status']) {
                'success' => 100,
                'warning' => 70,
                default => 30,
            };
            if (($source['recordCount'] ?? 0) === 0) {
                $missing++;
            } elseif ($source['status'] === 'success') {
                $fresh++;
            } else {
                $stale++;
            }
        }

        $score = (int) round($score / count($sources));

        return [
            'score' => $score,
            'status' => $score >= 85 ? 'success' : ($score >= 65 ? 'warning' : 'critical'),
            'freshSourceCount' => $fresh,
            'staleSourceCount' => $stale,
            'missingSourceCount' => $missing,
        ];
    }

    /** @param array<int,array<string,mixed>> $sources */
    private function lineageSummary(array $sources, array $trust): string
    {
        if ($sources === []) {
            return 'No curated source lineage is registered yet.';
        }

        $labels = collect($sources)->pluck('label')->implode(', ');

        return "{$trust['score']}% trust from ".count($sources)." source(s): {$labels}.";
    }

    /** @param array<int,array<string,mixed>> $steps */
    private function materializeMetricCatalog(string $metricKey, array $metric, array $steps): void
    {
        if (! Schema::hasTable('ops.metric_definitions')) {
            return;
        }

        $definition = MetricDefinition::firstOrNew(['metric_key' => $metricKey]);
        if (! $definition->exists) {
            $definition->metric_definition_uuid = (string) Str::uuid();
        }

        $definition->fill([
            'label' => $metric['label'],
            'domain' => $metric['domain'],
            'definition' => $metric['definition'],
            'owner' => $metric['owner'],
            'unit' => $metric['unit'],
            'direction' => $metric['direction'],
            'cadence' => $metric['cadence'],
            'status' => 'active',
            'metadata' => ['source_keys' => $metric['source_keys']],
        ])->save();

        foreach ($steps as $step) {
            MetricLineage::updateOrCreate(
                [
                    'metric_key' => $metricKey,
                    'source_key' => $step['sourceKey'],
                    'transform_name' => $step['transformName'],
                ],
                [
                    'metric_definition_id' => $definition->metric_definition_id,
                    'source_schema' => $step['sourceSchema'],
                    'source_table' => $step['sourceTable'],
                    'source_column' => $step['sourceColumn'],
                    'freshness_column' => $step['freshnessColumn'],
                    'transform_step' => $step['step'],
                    'confidence_weight' => $step['confidenceWeight'],
                    'source_filter' => [],
                    'metadata' => ['description' => $step['description']],
                ]
            );
        }
    }

    /** @param array<string,mixed> $entry */
    private function materializeSourceFreshness(array $entry): void
    {
        if (! Schema::hasTable('ops.source_freshness') || empty($entry['source'])) {
            return;
        }

        [$schema, $table] = explode('.', $entry['source'], 2);

        SourceFreshness::updateOrCreate(
            ['source_key' => $entry['sourceKey']],
            [
                'source_label' => $entry['label'],
                'source_schema' => $schema,
                'source_table' => $table,
                'freshness_column' => $entry['freshnessColumn'],
                'latest_observed_at' => $entry['latestObservedAtIso'],
                'expected_lag_minutes' => $entry['expectedLagMinutes'],
                'warning_lag_minutes' => $entry['warningLagMinutes'],
                'record_count' => $entry['recordCount'],
                'status' => $entry['status'],
                'checked_at' => now(),
                'metadata' => [
                    'route' => $entry['route'],
                    'scope' => $entry['scope'],
                    'freshness_label' => $entry['freshnessLabel'],
                ],
            ]
        );
    }

    private function tableExists(string $table): bool
    {
        $row = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS table_exists', [$table]);

        return (bool) ($row?->table_exists ?? false);
    }

    private function latestTimestamp(string $table, string $column): ?Carbon
    {
        if (! $this->tableExists($table)) {
            return null;
        }

        $value = DB::table($table)->max($column);

        return $value ? Carbon::parse($value) : null;
    }

    private function freshnessLabel(?Carbon $timestamp): string
    {
        if ($timestamp === null) {
            return 'no records';
        }

        $seconds = max(0, (int) round($timestamp->diffInSeconds(now())));
        if ($seconds < 90 * 60) {
            return DurationFormatter::seconds($seconds).' ago';
        }
        if ($seconds < 60 * 60 * 48) {
            return DurationFormatter::seconds($seconds).' ago';
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
}
