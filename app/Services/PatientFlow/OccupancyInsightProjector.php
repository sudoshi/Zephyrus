<?php

namespace App\Services\PatientFlow;

use App\Support\Operations\DurationFormatter;
use Carbon\CarbonImmutable;

class OccupancyInsightProjector
{
    private const LONG_STAY_WARN_MINUTES = 8 * 60;

    private const READY_MOVE_WINDOW_MINUTES = 30;

    private const OVERDUE_TIMER_WINDOW_MINUTES = 4 * 60;

    public function __construct(private readonly BarrierTaxonomyService $barriers) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, array<string, mixed>>  $locations
     * @param  list<array<string, mixed>>  $projections
     * @param  array<string, mixed>  $lens
     * @param  list<array<string, mixed>>  $operationalBarriers
     * @return array{asOf: string, occupancy: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function project(
        array $events,
        array $locations,
        array $projections,
        ?string $asOf,
        array $lens,
        array $operationalBarriers = [],
    ): array {
        $time = $asOf ? CarbonImmutable::parse($asOf) : CarbonImmutable::now();
        $tracks = $this->tracks($events);
        $identityVisible = ($lens['patient_dots'] ?? null) === 'full';

        $items = [];
        foreach ($tracks as $patientRef => $track) {
            $state = $this->stateAt($patientRef, $track, $time);
            if (! $state) {
                continue;
            }

            $event = $state['event'];
            $locationCode = (string) ($event['to_location'] ?? '');
            if ($locationCode === '' || ! isset($locations[$locationCode])) {
                continue;
            }

            $loc = $locations[$locationCode];
            $arrivedAt = CarbonImmutable::parse((string) $event['occurred_at']);
            $stayMinutes = max(0, (int) round($arrivedAt->diffInSeconds($time, false))) / 60;
            $durationRisk = $this->stayTimer($stayMinutes);
            $projectionTimers = array_map(
                fn (array $projection): array => $this->projectionTimer($projection, $time),
                $this->nearbyProjections($event, $projections, $time),
            );
            $operationalTimers = array_map(
                fn (array $record): array => $this->operationalBarrierTimer($record, $identityVisible),
                $this->matchingOperationalBarriers(
                    ['unit_code' => $event['unit_code'] ?? $loc['unit_code'] ?? null] + $event,
                    $operationalBarriers,
                ),
            );
            $knownNext = $state['next_event'] ? $this->eventTimer($state['next_event'], $time) : null;
            $timers = array_values(array_filter([
                $durationRisk,
                $knownNext,
                ...$projectionTimers,
                ...$operationalTimers,
            ]));
            usort($timers, fn (array $a, array $b): int => $this->rank($b['status']) <=> $this->rank($a['status']));

            $blockingTimers = array_values(array_filter($timers, fn (array $timer): bool => $timer['status'] !== 'ok'));
            $barrierTimers = array_values(array_filter(
                $blockingTimers,
                fn (array $timer): bool => $this->nullableString($timer['barrier_code'] ?? null) !== null,
            ));
            $verifiedTimers = array_values(array_filter(
                $barrierTimers,
                fn (array $timer): bool => ($timer['verified'] ?? false) === true,
            ));
            $riskTimers = array_values(array_filter(
                $blockingTimers,
                fn (array $timer): bool => ($timer['classification'] ?? null) === 'duration_risk',
            ));
            $blockers = array_values(array_unique(array_map(
                fn (array $timer): string => (string) $timer['label'],
                $barrierTimers,
            )));

            $item = [
                'key' => $patientRef.':'.$locationCode,
                // Internal authorization bridge. PatientFlowOccupancyContextService
                // always passes the projected row through FlowLensService before
                // it leaves the process.
                '_patient_ref' => $event['patient_id'] ?? null,
                'unit_id' => $event['unit_id'] ?? null,
                'location' => $locationCode,
                'location_name' => $event['location_name'] ?? $loc['name'] ?? null,
                'unit_code' => $event['unit_code'] ?? $loc['unit_code'] ?? null,
                'service_line' => $event['service_line'] ?? $event['location_service_line'] ?? $loc['service_line'] ?? null,
                'position_m' => $event['position_m'] ?? $loc['position_m'] ?? null,
                'stay_minutes' => $stayMinutes,
                'arrived_at' => $arrivedAt->toJSON(),
                'came_from' => $state['came_from'],
                'next_move' => $state['next_event']['to_location'] ?? ($projectionTimers[0]['label'] ?? null),
                'next_move_at' => $state['next_event']['occurred_at'] ?? ($projectionTimers[0]['due_at'] ?? null),
                'primary_status' => $this->strongestStatus(array_column($timers, 'status')),
                'timers' => $timers,
                'blockers' => $blockers,
                'risk_signals' => $this->uniqueTimerValues($riskTimers, 'label'),
                'duration_risk' => $this->durationRiskSummary($durationRisk),
                'verified_barriers' => $this->verifiedBarrierSummaries($verifiedTimers),
                'barrier_reasons' => $this->uniqueTimerValues($barrierTimers, 'reason'),
                'barrier_codes' => $this->uniqueTimerValues($barrierTimers, 'barrier_code'),
                'barrier_labels' => $this->uniqueTimerValues($barrierTimers, 'barrier_label'),
                'owner_roles' => $this->uniqueTimerValues($barrierTimers, 'owner_role'),
                'delay_impacts' => $this->uniqueTimerValues($barrierTimers, 'impact'),
                'rtdc_metrics' => $this->uniqueNestedTimerValues($barrierTimers, 'rtdc_metrics'),
                'eddy_summaries' => $this->uniqueTimerValues($barrierTimers, 'eddy_summary'),
                'barrier_owner_map' => $this->barrierOwnerMap($barrierTimers),
            ];

            if ($identityVisible) {
                $item += [
                    'patient_id' => $event['patient_id'] ?? null,
                    'patient_display_id' => $event['patient_display_id'] ?? null,
                    'encounter_id' => $event['encounter_id'] ?? null,
                ];
            }

            $items[] = $item;
        }

        return [
            'asOf' => $time->toJSON(),
            'occupancy' => $items,
            'summary' => $this->summary($items),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, list<array<string, mixed>>>
     */
    private function tracks(array $events): array
    {
        usort($events, fn (array $a, array $b): int => strcmp((string) $a['occurred_at'], (string) $b['occurred_at']));
        $tracks = [];
        foreach ($events as $event) {
            $patientRef = (string) ($event['patient_id'] ?? '');
            if ($patientRef === '') {
                continue;
            }
            $tracks[$patientRef][] = $event;
        }

        return $tracks;
    }

    /**
     * @param  list<array<string, mixed>>  $track
     * @return array{event: array<string, mixed>, next_event: ?array<string, mixed>, came_from: ?string}|null
     */
    private function stateAt(string $patientRef, array $track, CarbonImmutable $time): ?array
    {
        $current = null;
        $currentIndex = null;
        foreach ($track as $index => $event) {
            $occurred = CarbonImmutable::parse((string) $event['occurred_at']);
            if ($occurred->greaterThan($time)) {
                break;
            }

            if (in_array($event['event_type'] ?? null, ['discharge', 'cancel_admit'], true)) {
                $current = null;
                $currentIndex = null;

                continue;
            }

            if (! empty($event['to_location'])) {
                $current = $event;
                $currentIndex = $index;
            }
        }

        if (! $current || $currentIndex === null) {
            return null;
        }

        $previousMovement = null;
        for ($index = $currentIndex - 1; $index >= 0; $index--) {
            if (($track[$index]['event_category'] ?? null) === 'movement' && ! empty($track[$index]['to_location'])) {
                $previousMovement = $track[$index];
                break;
            }
        }

        $next = null;
        for ($index = $currentIndex + 1; $index < count($track); $index++) {
            if (CarbonImmutable::parse((string) $track[$index]['occurred_at'])->greaterThan($time)) {
                $next = $track[$index];
                break;
            }
        }

        return [
            'event' => $current,
            'next_event' => $next,
            'came_from' => $current['from_location'] ?? $previousMovement['to_location'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $projections */
    private function nearbyProjections(array $event, array $projections, CarbonImmutable $time): array
    {
        $items = array_filter($projections, function (array $item) use ($event, $time): bool {
            if (! in_array($item['kind'] ?? null, ['expected_discharge', 'transport_due', 'evs_due', 'scheduled_or_case'], true)) {
                return false;
            }

            $t = CarbonImmutable::parse((string) $item['t']);
            if ($t->lessThan($time->subMinutes(self::OVERDUE_TIMER_WINDOW_MINUTES))) {
                return false;
            }

            return $this->projectionMatchRank($item, $event) > 0;
        });

        $exact = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->projectionMatchRank($item, $event) >= 4,
        ));
        $items = $exact !== [] ? $exact : array_values($items);

        usort($items, function (array $a, array $b) use ($event): int {
            return $this->projectionMatchRank($b, $event) <=> $this->projectionMatchRank($a, $event)
                ?: strcmp((string) $a['t'], (string) $b['t']);
        });

        return array_slice(array_values($items), 0, 4);
    }

    private function projectionMatchRank(array $item, array $event): int
    {
        if (! empty($item['_patient_ref']) && (string) $item['_patient_ref'] === (string) ($event['patient_id'] ?? '')) {
            return 5;
        }

        $entityRef = isset($item['entity']['ref']) ? (string) $item['entity']['ref'] : null;
        if ($entityRef && in_array($entityRef, [$event['encounter_id'] ?? null, $event['patient_id'] ?? null, $event['patient_display_id'] ?? null], true)) {
            return 4;
        }

        if (($item['bed_id'] ?? null) !== null && ! empty($event['bed']) && (string) $item['bed_id'] === (string) $event['bed']) {
            return 3;
        }

        if (! empty($item['room']) && ! empty($event['room']) && strtolower((string) $item['room']) === strtolower((string) $event['room'])) {
            return 2;
        }

        return 0;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function matchingOperationalBarriers(array $event, array $records): array
    {
        $matched = [];
        foreach ($records as $record) {
            $matchedBy = $this->operationalBarrierMatch($record, $event);
            if (! $matchedBy) {
                continue;
            }

            $record['matched_by'] = $matchedBy;
            $matched[] = $record;
        }

        return $matched;
    }

    private function operationalBarrierMatch(array $record, array $event): ?string
    {
        $eventEncounter = $this->nullableString($event['encounter_id'] ?? null);
        $eventPatient = $this->nullableString($event['patient_id'] ?? null);

        if (($record['record_type'] ?? null) === 'transport_delay') {
            if ($eventEncounter && $eventEncounter === $this->nullableString($record['encounter_ref'] ?? null)) {
                return 'encounter_ref';
            }

            if ($eventPatient && $eventPatient === $this->nullableString($record['patient_ref'] ?? null)) {
                return 'patient_ref';
            }

            return null;
        }

        if ($eventEncounter && $eventEncounter === $this->nullableString($record['flow_encounter_ref'] ?? null)) {
            return 'flow_encounter_link';
        }

        if ($eventPatient && $eventPatient === $this->nullableString($record['flow_patient_ref'] ?? null)) {
            return 'flow_patient_link';
        }

        if ($eventPatient && $eventPatient === $this->nullableString($record['patient_ref'] ?? null)) {
            return 'patient_ref';
        }

        // A patient/encounter barrier must never be broadcast to every patient
        // in its unit if its explicit identity bridge does not match.
        if (($record['encounter_specific'] ?? false) === true) {
            return null;
        }

        $recordUnit = $this->normalizedUnitCode($record['unit_code'] ?? null);
        $eventUnit = $this->normalizedUnitCode($event['unit_code'] ?? null);

        return $recordUnit && $recordUnit === $eventUnit ? 'unit_id' : null;
    }

    /** @return array<string, mixed> */
    private function operationalBarrierTimer(array $record, bool $identityVisible): array
    {
        $code = (string) ($record['barrier_code'] ?? 'unclassified_barrier');
        $definition = $this->barriers->definition($code);
        $sourceTable = (string) ($record['source_table'] ?? 'operational_source');
        $sourceRecordId = (string) ($record['source_record_id'] ?? 'unknown');
        $verification = is_array($record['verification'] ?? null) ? $record['verification'] : [];
        $verification['matched_by'] = (string) ($record['matched_by'] ?? 'unknown');

        if (($record['record_type'] ?? null) === 'transport_delay') {
            $overdue = abs((float) ($record['minutes_remaining'] ?? 0));
            $reason = sprintf(
                '%s transport is %s overdue in %s status.',
                ucfirst(str_replace('_', ' ', (string) ($record['request_type'] ?? 'patient'))),
                DurationFormatter::minutes($overdue),
                str_replace('_', ' ', (string) ($record['transport_status'] ?? 'active')),
            );
            $blocks = $identityVisible
                ? trim(implode(' to ', array_filter([
                    $this->nullableString($record['origin'] ?? null),
                    $this->nullableString($record['destination'] ?? null),
                ]))) ?: 'Next patient movement'
                : 'Next patient movement';
            $owner = $identityVisible
                ? $this->nullableString($record['assigned_team'] ?? null)
                    ?? $this->nullableString($record['assigned_vendor'] ?? null)
                : null;
        } else {
            $reason = $identityVisible
                ? $this->nullableString($record['description'] ?? null)
                    ?? 'A verified '.str_replace('_', ' ', (string) ($record['category'] ?? 'operational')).' barrier is open.'
                : 'A verified '.str_replace('_', ' ', (string) ($record['category'] ?? 'operational')).' barrier is open.';
            $blocks = 'Patient progression';
            $owner = $identityVisible ? $this->nullableString($record['owner'] ?? null) : null;
        }

        return $this->decorateBarrierTimer([
            'kind' => (string) ($definition['timer_kind'] ?? 'readiness'),
            'label' => (string) ($definition['label'] ?? 'Operational barrier'),
            'due_at' => $record['due_at'] ?? null,
            'minutes_remaining' => $record['minutes_remaining'] ?? null,
            'status' => (string) ($record['status'] ?? 'delayed'),
            'source' => $sourceTable,
            'reason' => $reason,
            'barrier_code' => $code,
            'source_reason_code' => $this->nullableString($record['source_reason_code'] ?? null),
            'owner_role' => (string) ($definition['owner_role'] ?? 'bed_manager'),
            'owner' => $owner,
            'blocks' => $blocks,
            'impact' => 'Verified operational dependency is delaying patient flow.',
            'classification' => 'verified_barrier',
            'verified' => true,
            'verification' => $verification,
            'provenance' => [
                'source_table' => $sourceTable,
                'source_record_id' => $sourceRecordId,
                'record_type' => (string) ($record['record_type'] ?? 'operational_barrier'),
            ],
        ]);
    }

    private function eventTimer(array $event, CarbonImmutable $time): array
    {
        $minutes = $this->minutesUntil($time, (string) $event['occurred_at']);
        $movement = ($event['event_category'] ?? null) === 'movement';
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

        return $this->decorateBarrierTimer([
            'kind' => $movement ? 'next_transport' : 'readiness',
            'label' => $movement && ! empty($event['to_location'])
                ? 'Next '.$event['to_location']
                : str_replace('_', ' ', (string) ($event['event_type'] ?? 'readiness')),
            'due_at' => $event['occurred_at'],
            'minutes_remaining' => $minutes,
            'status' => $this->timerStatus($minutes),
            'source' => $movement ? 'movement' : (string) ($event['event_category'] ?? 'event'),
            'reason' => $this->nullableString($metadata['reason'] ?? $metadata['barrier_reason'] ?? $metadata['delay_reason'] ?? null),
            'barrier_code' => $this->nullableString($metadata['barrier_code'] ?? null),
            'owner_role' => $this->nullableString($metadata['owner_role'] ?? null),
            'blocks' => $this->nullableString($metadata['blocks'] ?? null),
            'impact' => $this->nullableString($metadata['impact'] ?? null),
        ]);
    }

    private function projectionTimer(array $item, CarbonImmutable $time): array
    {
        $minutes = $this->minutesUntil($time, (string) $item['t']);
        $barrier = is_array($item['barrier'] ?? null) ? $item['barrier'] : [];
        $barrierCode = $this->nullableString($item['barrier_code'] ?? $barrier['code'] ?? null);
        $explicitKind = $this->nullableString($item['timer_kind'] ?? null);
        $kind = in_array($explicitKind, ['stay', 'arrival_transport', 'next_transport', 'evs', 'readiness'], true)
            ? $explicitKind
            : match ($item['kind']) {
                'evs_due' => 'evs',
                'transport_due' => 'next_transport',
                default => 'readiness',
            };

        return $this->decorateBarrierTimer([
            'kind' => $kind,
            'label' => $this->nullableString($item['label'] ?? null) ?? match ($item['kind']) {
                'expected_discharge' => 'Discharge',
                'transport_due' => 'Transport',
                'evs_due' => 'EVS turn',
                'scheduled_or_case' => 'OR',
                default => $item['label'] ?? 'Timer',
            },
            'due_at' => $item['t'],
            'minutes_remaining' => $minutes,
            'status' => $this->timerStatus($minutes),
            'source' => (string) ($item['provenance']['service'] ?? 'projection'),
            'reason' => $this->nullableString($item['reason'] ?? $barrier['reason'] ?? null),
            'barrier_code' => $barrierCode,
            'owner_role' => $this->nullableString($item['owner_role'] ?? $barrier['owner_role'] ?? null),
            'blocks' => $this->nullableString($item['blocks'] ?? $barrier['blocks'] ?? null),
            'impact' => $this->nullableString($item['impact'] ?? $barrier['impact'] ?? null),
        ]);
    }

    private function stayTimer(float $stayMinutes): array
    {
        // F-3 ruling (2026-07-19): inferred, duration-only risk is capped at
        // watch — coral requires a verified breach (earned-urgency doctrine).
        // Per-service validated stay targets are the planned refinement.
        $status = $stayMinutes >= self::LONG_STAY_WARN_MINUTES ? 'watch' : 'ok';
        $definition = $this->barriers->definition('long_stay_capacity_risk');

        return [
            'kind' => 'stay',
            'label' => 'Stay',
            'due_at' => null,
            'minutes_remaining' => null,
            'status' => $status,
            'source' => 'elapsed occupancy',
            'reason' => $status === 'ok' ? null : 'Elapsed occupancy has crossed the RTDC stay-duration threshold.',
            'barrier_code' => null,
            'barrier_label' => null,
            'barrier_category' => null,
            'risk_code' => $status === 'ok' ? null : 'long_stay_capacity_risk',
            'risk_label' => $status === 'ok' ? null : (string) ($definition['label'] ?? 'Long-stay capacity risk'),
            'risk_category' => $status === 'ok' ? null : (string) ($definition['category'] ?? 'capacity'),
            'owner_role' => $status === 'ok' ? null : 'bed_manager',
            'blocks' => $status === 'ok' ? null : 'Capacity release',
            'impact' => $status === 'ok' ? null : 'Long-stay occupancy compounds bed availability risk.',
            'rtdc_metrics' => $status === 'ok' ? [] : $this->barriers->rtdcMetricsFor('long_stay_capacity_risk'),
            'eddy_summary' => $status === 'ok' ? null : $this->barriers->eddySummaryFor('long_stay_capacity_risk'),
            'recommended_focus' => $status === 'ok' ? null : $this->nullableString($definition['recommended_focus'] ?? null),
            'classification' => 'duration_risk',
            'verified' => false,
            'verification' => [
                'status' => 'inferred',
                'assertion' => 'elapsed_duration_threshold',
                'matched_by' => 'occupancy_elapsed_time',
            ],
            'provenance' => [
                'source_table' => 'flow_core.flow_events',
                'source_record_id' => null,
                'record_type' => 'derived_duration_risk',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $timer
     * @return array<string, mixed>
     */
    private function decorateBarrierTimer(array $timer): array
    {
        $code = $this->nullableString($timer['barrier_code'] ?? null);
        if (! $code) {
            return array_merge($timer, [
                'barrier_code' => null,
                'barrier_label' => null,
                'barrier_category' => null,
                'rtdc_metrics' => [],
                'eddy_summary' => null,
                'recommended_focus' => null,
                'classification' => $timer['classification'] ?? 'projected_risk',
                'verified' => (bool) ($timer['verified'] ?? false),
                'verification' => is_array($timer['verification'] ?? null)
                    ? $timer['verification']
                    : ['status' => 'unverified'],
                'provenance' => is_array($timer['provenance'] ?? null)
                    ? $timer['provenance']
                    : [
                        'source_table' => $this->nullableString($timer['source'] ?? null),
                        'source_record_id' => null,
                        'record_type' => 'projected_timer',
                    ],
            ]);
        }

        $definition = $this->barriers->definition($code);
        $minutes = isset($timer['minutes_remaining']) && $timer['minutes_remaining'] !== null
            ? (float) $timer['minutes_remaining']
            : null;

        return array_merge($timer, [
            'barrier_code' => (string) $definition['code'],
            'barrier_label' => (string) ($definition['label'] ?? 'Barrier'),
            'barrier_category' => (string) ($definition['category'] ?? 'capacity'),
            'owner_role' => $this->nullableString($timer['owner_role'] ?? null) ?? $this->barriers->ownerFor($code),
            'rtdc_metrics' => $this->barriers->rtdcMetricsFor($code),
            'eddy_summary' => $this->barriers->eddySummaryFor($code),
            'recommended_focus' => $this->nullableString($definition['recommended_focus'] ?? null),
            'status' => $minutes !== null ? $this->barriers->statusFor($code, $minutes) : (string) ($timer['status'] ?? 'ok'),
            'classification' => $timer['classification'] ?? 'projected_barrier',
            'verified' => (bool) ($timer['verified'] ?? false),
            'verification' => is_array($timer['verification'] ?? null)
                ? $timer['verification']
                : ['status' => 'unverified'],
            'provenance' => is_array($timer['provenance'] ?? null)
                ? $timer['provenance']
                : [
                    'source_table' => $this->nullableString($timer['source'] ?? null),
                    'source_record_id' => null,
                    'record_type' => 'projected_barrier',
                ],
        ]);
    }

    private function minutesUntil(CarbonImmutable $from, string $iso): ?float
    {
        return ((int) round(CarbonImmutable::parse($iso)->diffInSeconds($from, false))) / -60;
    }

    private function timerStatus(?float $minutesRemaining): string
    {
        if ($minutesRemaining === null) {
            return 'ok';
        }

        if ($minutesRemaining < 0) {
            return 'delayed';
        }

        return $minutesRemaining <= self::READY_MOVE_WINDOW_MINUTES ? 'watch' : 'ok';
    }

    /** @param list<string> $statuses */
    private function strongestStatus(array $statuses): string
    {
        return array_reduce(
            $statuses,
            fn (string $best, string $status): string => $this->rank($status) > $this->rank($best) ? $status : $best,
            'ok',
        );
    }

    private function rank(string $status): int
    {
        return match ($status) {
            'delayed' => 2,
            'watch' => 1,
            default => 0,
        };
    }

    /** @param list<array<string, mixed>> $items */
    private function summary(array $items): array
    {
        $summary = [
            'active' => count($items),
            'delayed' => 0,
            'watch' => 0,
            'transport_delays' => 0,
            'evs_delays' => 0,
            'ready_to_move' => 0,
            'duration_risks' => 0,
            'verified_barriers' => 0,
            'avg_stay_minutes' => 0,
            'service_lines' => [],
            'persona' => ['transport' => 0, 'evs' => 0, 'bed_manager' => 0, 'capacity' => 0],
            'timer_status_counts' => ['ok' => 0, 'watch' => 0, 'delayed' => 0],
            'top_barriers' => [],
        ];

        $service = [];
        $barriers = [];
        $stayTotal = 0.0;
        foreach ($items as $item) {
            $stayTotal += (float) $item['stay_minutes'];
            $status = (string) $item['primary_status'];
            if ($status === 'delayed') {
                $summary['delayed']++;
            } elseif ($status === 'watch') {
                $summary['watch']++;
            }

            foreach ($item['timers'] as $timer) {
                $summary['timer_status_counts'][$timer['status']]++;
                if ($timer['status'] !== 'ok' && ($timer['classification'] ?? null) === 'duration_risk') {
                    $summary['duration_risks']++;
                }
                if ($timer['status'] !== 'ok' && ($timer['verified'] ?? false) === true) {
                    $summary['verified_barriers']++;
                }
                if (
                    $timer['status'] !== 'ok'
                    && ($timer['kind'] ?? null) !== 'stay'
                    && $this->nullableString($timer['barrier_code'] ?? null) !== null
                ) {
                    $barrierCode = $this->nullableString($timer['barrier_code'] ?? null);
                    $barrierKey = $barrierCode ?: implode('|', [
                        (string) ($timer['label'] ?? 'Barrier'),
                        (string) ($timer['reason'] ?? ''),
                        (string) ($timer['owner_role'] ?? ''),
                    ]);
                    $barriers[$barrierKey] ??= [
                        'barrier_code' => $barrierCode,
                        'label' => (string) ($timer['barrier_label'] ?? $timer['label'] ?? 'Barrier'),
                        'reason' => $this->nullableString($timer['reason'] ?? null),
                        'owner_role' => $this->nullableString($timer['owner_role'] ?? null),
                        'barrier_category' => $this->nullableString($timer['barrier_category'] ?? null),
                        'rtdc_metrics' => [],
                        'eddy_summary' => $this->nullableString($timer['eddy_summary'] ?? null),
                        'recommended_focus' => $this->nullableString($timer['recommended_focus'] ?? null),
                        'verified_count' => 0,
                        'sources' => [],
                        'count' => 0,
                        'service_lines' => [],
                    ];
                    $barriers[$barrierKey]['count']++;
                    if (($timer['verified'] ?? false) === true) {
                        $barriers[$barrierKey]['verified_count']++;
                    }
                    if ($source = $this->nullableString($timer['provenance']['source_table'] ?? null)) {
                        $barriers[$barrierKey]['sources'][] = $source;
                    }
                    $barriers[$barrierKey]['rtdc_metrics'] = array_values(array_unique([
                        ...$barriers[$barrierKey]['rtdc_metrics'],
                        ...(is_array($timer['rtdc_metrics'] ?? null) ? $timer['rtdc_metrics'] : []),
                    ]));
                    if (! empty($item['service_line'])) {
                        $barriers[$barrierKey]['service_lines'][] = str_replace('_', ' ', (string) $item['service_line']);
                    }
                }
            }

            $transport = collect($item['timers'])->contains(fn (array $timer): bool => in_array($timer['kind'], ['arrival_transport', 'next_transport'], true) && $timer['status'] !== 'ok');
            $evs = collect($item['timers'])->contains(fn (array $timer): bool => $timer['kind'] === 'evs' && $timer['status'] !== 'ok');
            $ready = collect($item['timers'])->contains(fn (array $timer): bool => $timer['minutes_remaining'] !== null
                && $timer['minutes_remaining'] >= 0
                && $timer['minutes_remaining'] <= self::READY_MOVE_WINDOW_MINUTES);

            if ($transport) {
                $summary['transport_delays']++;
            }
            if ($evs) {
                $summary['evs_delays']++;
            }
            if ($ready) {
                $summary['ready_to_move']++;
            }

            $key = (string) ($item['service_line'] ?: 'unassigned');
            $service[$key] ??= ['service_line' => str_replace('_', ' ', $key), 'occupied' => 0, 'delayed' => 0, 'watch' => 0, 'avg_stay_minutes' => 0, '_stay' => 0];
            $service[$key]['occupied']++;
            $service[$key]['_stay'] += (float) $item['stay_minutes'];
            if ($status === 'delayed') {
                $service[$key]['delayed']++;
            } elseif ($status === 'watch') {
                $service[$key]['watch']++;
            }
        }

        foreach ($service as $row) {
            $row['avg_stay_minutes'] = $row['occupied'] > 0 ? $row['_stay'] / $row['occupied'] : 0;
            unset($row['_stay']);
            $summary['service_lines'][] = $row;
        }

        usort($summary['service_lines'], fn (array $a, array $b): int => ($b['delayed'] + $b['watch']) <=> ($a['delayed'] + $a['watch']) ?: $b['occupied'] <=> $a['occupied']);
        $summary['service_lines'] = array_slice($summary['service_lines'], 0, 8);
        $summary['top_barriers'] = array_values(array_map(function (array $row): array {
            $row['service_lines'] = array_values(array_unique($row['service_lines']));
            $row['sources'] = array_values(array_unique($row['sources']));

            return $row;
        }, $barriers));
        usort($summary['top_barriers'], fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        $summary['top_barriers'] = array_slice($summary['top_barriers'], 0, 5);
        $summary['avg_stay_minutes'] = count($items) > 0 ? $stayTotal / count($items) : 0;
        $summary['persona'] = [
            'transport' => $summary['transport_delays'],
            'evs' => $summary['evs_delays'],
            'bed_manager' => $summary['ready_to_move'] + $summary['delayed'],
            'capacity' => $summary['delayed'] + $summary['watch'],
        ];

        return $summary;
    }

    /** @param list<array<string, mixed>> $timers */
    private function uniqueTimerValues(array $timers, string $key): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $timer): ?string => $this->nullableString($timer[$key] ?? null),
            $timers,
        ))));
    }

    /** @param list<array<string, mixed>> $timers */
    private function uniqueNestedTimerValues(array $timers, string $key): array
    {
        $values = [];
        foreach ($timers as $timer) {
            foreach (is_array($timer[$key] ?? null) ? $timer[$key] : [] as $value) {
                $normalized = $this->nullableString($value);
                if ($normalized) {
                    $values[] = $normalized;
                }
            }
        }

        return array_values(array_unique($values));
    }

    /** @return array<string, mixed> */
    private function durationRiskSummary(array $timer): array
    {
        return [
            'status' => (string) ($timer['status'] ?? 'ok'),
            'classification' => 'duration_risk',
            'risk_code' => $this->nullableString($timer['risk_code'] ?? null),
            'risk_label' => $this->nullableString($timer['risk_label'] ?? null),
            'verified' => false,
            'verification' => $timer['verification'] ?? ['status' => 'inferred'],
            'provenance' => $timer['provenance'] ?? [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $timers
     * @return list<array<string, mixed>>
     */
    private function verifiedBarrierSummaries(array $timers): array
    {
        return array_values(array_map(fn (array $timer): array => [
            'barrier_code' => $this->nullableString($timer['barrier_code'] ?? null),
            'label' => $this->nullableString($timer['barrier_label'] ?? $timer['label'] ?? null),
            'category' => $this->nullableString($timer['barrier_category'] ?? null),
            'reason' => $this->nullableString($timer['reason'] ?? null),
            'owner_role' => $this->nullableString($timer['owner_role'] ?? null),
            'owner' => $this->nullableString($timer['owner'] ?? null),
            'status' => (string) ($timer['status'] ?? 'delayed'),
            'verified' => true,
            'verification' => $timer['verification'] ?? ['status' => 'verified'],
            'provenance' => $timer['provenance'] ?? [],
        ], $timers));
    }

    /** @param list<array<string, mixed>> $timers */
    private function barrierOwnerMap(array $timers): array
    {
        $map = [];
        foreach ($timers as $timer) {
            $code = $this->nullableString($timer['barrier_code'] ?? null);
            if (! $code) {
                continue;
            }

            $map[$code] = [
                'label' => $this->nullableString($timer['barrier_label'] ?? null),
                'owner_role' => $this->nullableString($timer['owner_role'] ?? null),
                'verified' => (bool) ($timer['verified'] ?? false),
                'source_table' => $this->nullableString($timer['provenance']['source_table'] ?? null),
            ];
        }

        return $map;
    }

    private function normalizedUnitCode(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value ? strtoupper($value) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
