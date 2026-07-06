<?php

namespace App\Services\PatientFlow;

use Carbon\CarbonImmutable;

class OccupancyInsightProjector
{
    private const LONG_STAY_WARN_MINUTES = 8 * 60;

    private const LONG_STAY_DELAY_MINUTES = 18 * 60;

    private const READY_MOVE_WINDOW_MINUTES = 30;

    private const OVERDUE_TIMER_WINDOW_MINUTES = 4 * 60;

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, array<string, mixed>>  $locations
     * @param  list<array<string, mixed>>  $projections
     * @param  array<string, mixed>  $lens
     * @return array{asOf: string, occupancy: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function project(array $events, array $locations, array $projections, ?string $asOf, array $lens): array
    {
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
            $stayMinutes = max(0, $arrivedAt->diffInMinutes($time, false));
            $projectionTimers = array_map(
                fn (array $projection): array => $this->projectionTimer($projection, $time),
                $this->nearbyProjections($event, $projections, $time),
            );
            $knownNext = $state['next_event'] ? $this->eventTimer($state['next_event'], $time) : null;
            $timers = array_values(array_filter([
                $this->stayTimer($stayMinutes),
                $knownNext,
                ...$projectionTimers,
            ]));
            usort($timers, fn (array $a, array $b): int => $this->rank($b['status']) <=> $this->rank($a['status']));

            $blockers = array_values(array_unique(array_map(
                fn (array $timer): string => (string) $timer['label'],
                array_filter($timers, fn (array $timer): bool => $timer['status'] !== 'ok'),
            )));
            $blockingTimers = array_values(array_filter($timers, fn (array $timer): bool => $timer['status'] !== 'ok'));

            $item = [
                'key' => $patientRef.':'.$locationCode,
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
                'barrier_reasons' => $this->uniqueTimerValues($blockingTimers, 'reason'),
                'owner_roles' => $this->uniqueTimerValues($blockingTimers, 'owner_role'),
                'delay_impacts' => $this->uniqueTimerValues($blockingTimers, 'impact'),
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

            $entityRef = isset($item['entity']['ref']) ? (string) $item['entity']['ref'] : null;
            if ($entityRef && in_array($entityRef, [$event['encounter_id'] ?? null, $event['patient_id'] ?? null, $event['patient_display_id'] ?? null], true)) {
                return true;
            }

            if (($item['bed_id'] ?? null) !== null && ! empty($event['bed']) && (string) $item['bed_id'] === (string) $event['bed']) {
                return true;
            }

            if (! empty($item['room']) && ! empty($event['room']) && strtolower((string) $item['room']) === strtolower((string) $event['room'])) {
                return true;
            }

            return false;
        });

        usort($items, fn (array $a, array $b): int => strcmp((string) $a['t'], (string) $b['t']));

        return array_slice(array_values($items), 0, 4);
    }

    private function eventTimer(array $event, CarbonImmutable $time): array
    {
        $minutes = $this->minutesUntil($time, (string) $event['occurred_at']);
        $movement = ($event['event_category'] ?? null) === 'movement';
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

        return [
            'kind' => $movement ? 'next_transport' : 'readiness',
            'label' => $movement && ! empty($event['to_location'])
                ? 'Next '.$event['to_location']
                : str_replace('_', ' ', (string) ($event['event_type'] ?? 'readiness')),
            'due_at' => $event['occurred_at'],
            'minutes_remaining' => $minutes,
            'status' => $this->timerStatus($minutes),
            'source' => $movement ? 'movement' : (string) ($event['event_category'] ?? 'event'),
            'reason' => $this->nullableString($metadata['reason'] ?? $metadata['barrier_reason'] ?? $metadata['delay_reason'] ?? null),
            'owner_role' => $this->nullableString($metadata['owner_role'] ?? null),
            'blocks' => $this->nullableString($metadata['blocks'] ?? null),
            'impact' => $this->nullableString($metadata['impact'] ?? null),
        ];
    }

    private function projectionTimer(array $item, CarbonImmutable $time): array
    {
        $minutes = $this->minutesUntil($time, (string) $item['t']);
        $explicitKind = $this->nullableString($item['timer_kind'] ?? null);
        $kind = in_array($explicitKind, ['stay', 'arrival_transport', 'next_transport', 'evs', 'readiness'], true)
            ? $explicitKind
            : match ($item['kind']) {
                'evs_due' => 'evs',
                'transport_due' => 'next_transport',
                default => 'readiness',
            };

        return [
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
            'reason' => $this->nullableString($item['reason'] ?? $item['barrier']['reason'] ?? null),
            'owner_role' => $this->nullableString($item['owner_role'] ?? $item['barrier']['owner_role'] ?? null),
            'blocks' => $this->nullableString($item['blocks'] ?? $item['barrier']['blocks'] ?? null),
            'impact' => $this->nullableString($item['impact'] ?? $item['barrier']['impact'] ?? null),
        ];
    }

    private function stayTimer(int $stayMinutes): array
    {
        $status = $stayMinutes >= self::LONG_STAY_DELAY_MINUTES
            ? 'delayed'
            : ($stayMinutes >= self::LONG_STAY_WARN_MINUTES ? 'watch' : 'ok');

        return [
            'kind' => 'stay',
            'label' => 'Stay',
            'due_at' => null,
            'minutes_remaining' => null,
            'status' => $status,
            'source' => 'elapsed occupancy',
            'reason' => $status === 'ok' ? null : 'Elapsed occupancy has crossed the RTDC stay-duration threshold.',
            'owner_role' => $status === 'ok' ? null : 'bed_manager',
            'blocks' => $status === 'ok' ? null : 'Capacity release',
            'impact' => $status === 'ok' ? null : 'Long-stay occupancy compounds bed availability risk.',
        ];
    }

    private function minutesUntil(CarbonImmutable $from, string $iso): ?int
    {
        return CarbonImmutable::parse($iso)->diffInMinutes($from, false) * -1;
    }

    private function timerStatus(?int $minutesRemaining): string
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
            'avg_stay_minutes' => 0,
            'service_lines' => [],
            'persona' => ['transport' => 0, 'evs' => 0, 'bed_manager' => 0, 'capacity' => 0],
            'timer_status_counts' => ['ok' => 0, 'watch' => 0, 'delayed' => 0],
            'top_barriers' => [],
        ];

        $service = [];
        $barriers = [];
        $stayTotal = 0;
        foreach ($items as $item) {
            $stayTotal += (int) $item['stay_minutes'];
            $status = (string) $item['primary_status'];
            if ($status === 'delayed') {
                $summary['delayed']++;
            } elseif ($status === 'watch') {
                $summary['watch']++;
            }

            foreach ($item['timers'] as $timer) {
                $summary['timer_status_counts'][$timer['status']]++;
                if ($timer['status'] !== 'ok' && ($timer['kind'] ?? null) !== 'stay') {
                    $barrierKey = implode('|', [
                        (string) ($timer['label'] ?? 'Barrier'),
                        (string) ($timer['reason'] ?? ''),
                        (string) ($timer['owner_role'] ?? ''),
                    ]);
                    $barriers[$barrierKey] ??= [
                        'label' => (string) ($timer['label'] ?? 'Barrier'),
                        'reason' => $this->nullableString($timer['reason'] ?? null),
                        'owner_role' => $this->nullableString($timer['owner_role'] ?? null),
                        'count' => 0,
                        'service_lines' => [],
                    ];
                    $barriers[$barrierKey]['count']++;
                    if (! empty($item['service_line'])) {
                        $barriers[$barrierKey]['service_lines'][] = str_replace('_', ' ', (string) $item['service_line']);
                    }
                }
            }

            $transport = collect($item['timers'])->contains(fn (array $timer): bool => in_array($timer['kind'], ['arrival_transport', 'next_transport'], true) && $timer['status'] !== 'ok');
            $evs = collect($item['timers'])->contains(fn (array $timer): bool => $timer['kind'] === 'evs' && $timer['status'] !== 'ok');
            $ready = collect($item['timers'])->contains(fn (array $timer): bool => $timer['minutes_remaining'] !== null && $timer['minutes_remaining'] <= self::READY_MOVE_WINDOW_MINUTES);

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
            $service[$key]['_stay'] += (int) $item['stay_minutes'];
            if ($status === 'delayed') {
                $service[$key]['delayed']++;
            } elseif ($status === 'watch') {
                $service[$key]['watch']++;
            }
        }

        foreach ($service as $row) {
            $row['avg_stay_minutes'] = $row['occupied'] > 0 ? (int) round($row['_stay'] / $row['occupied']) : 0;
            unset($row['_stay']);
            $summary['service_lines'][] = $row;
        }

        usort($summary['service_lines'], fn (array $a, array $b): int => ($b['delayed'] + $b['watch']) <=> ($a['delayed'] + $a['watch']) ?: $b['occupied'] <=> $a['occupied']);
        $summary['service_lines'] = array_slice($summary['service_lines'], 0, 8);
        $summary['top_barriers'] = array_values(array_map(function (array $row): array {
            $row['service_lines'] = array_values(array_unique($row['service_lines']));

            return $row;
        }, $barriers));
        usort($summary['top_barriers'], fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
        $summary['top_barriers'] = array_slice($summary['top_barriers'], 0, 5);
        $summary['avg_stay_minutes'] = count($items) > 0 ? (int) round($stayTotal / count($items)) : 0;
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
