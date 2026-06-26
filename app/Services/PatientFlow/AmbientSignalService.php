<?php

namespace App\Services\PatientFlow;

use App\Models\PatientFlow\AmbientSignalAdapterDefinition;
use App\Models\PatientFlow\AmbientSignalEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AmbientSignalService
{
    public function __construct(private readonly FacilitySpaceLocationResolver $locations) {}

    /** @return array<string,mixed> */
    public function summary(string $facilityCode = 'ZEPHYRUS-500'): array
    {
        if (! $this->tablesExist()) {
            return $this->emptySummary();
        }

        $this->syncFixtures($facilityCode);

        $adapters = AmbientSignalAdapterDefinition::query()
            ->withCount('events')
            ->orderBy('adapter_key')
            ->get();
        $events = AmbientSignalEvent::query()
            ->with('adapter')
            ->orderByDesc('occurred_at')
            ->limit(25)
            ->get();
        $averageConfidence = $events->isNotEmpty()
            ? round($events->avg(fn (AmbientSignalEvent $event): float => (float) $event->confidence_score), 4)
            : 0.0;

        return [
            'generated_at' => now()->toJSON(),
            'summary' => [
                'adapterCount' => $adapters->count(),
                'enabledAdapterCount' => $adapters->where('enabled', true)->count(),
                'eventCount' => (int) AmbientSignalEvent::query()->count(),
                'averageConfidence' => $averageConfidence,
                'confidenceLevel' => $this->confidenceLevel($averageConfidence),
            ],
            'adapters' => $adapters
                ->map(fn (AmbientSignalAdapterDefinition $adapter): array => [
                    'adapterId' => $adapter->ambient_signal_adapter_id,
                    'adapterUuid' => $adapter->adapter_uuid,
                    'key' => $adapter->adapter_key,
                    'label' => $adapter->label,
                    'sourceType' => $adapter->source_type,
                    'enabled' => $adapter->enabled,
                    'baseConfidence' => (float) $adapter->base_confidence,
                    'minimumRole' => $adapter->minimum_role,
                    'capabilities' => $adapter->capability_payload ?? [],
                    'eventCount' => $adapter->events_count,
                ])
                ->values()
                ->all(),
            'events' => $events
                ->map(fn (AmbientSignalEvent $event): array => $this->serializeEvent($event))
                ->values()
                ->all(),
        ];
    }

    public function syncFixtures(string $facilityCode = 'ZEPHYRUS-500'): void
    {
        foreach ($this->fixtureAdapters() as $adapter) {
            $definition = $adapter->definition();
            /** @var AmbientSignalAdapterDefinition $model */
            $model = AmbientSignalAdapterDefinition::firstOrNew(['adapter_key' => $definition['adapter_key']]);
            if (! $model->exists) {
                $model->adapter_uuid = (string) Str::uuid();
            }

            $model->fill([
                'label' => $definition['label'],
                'source_type' => $definition['source_type'],
                'enabled' => $definition['enabled'] ?? true,
                'base_confidence' => $definition['base_confidence'],
                'minimum_role' => $definition['minimum_role'] ?? 'user',
                'capability_payload' => $definition['capabilities'] ?? [],
            ])->save();

            foreach ($adapter->fixtureEvents() as $event) {
                $location = $this->locations->resolve($event['location_code'] ?? null, $facilityCode);
                $confidence = $this->scoreConfidence((float) $definition['base_confidence'], $event);

                AmbientSignalEvent::updateOrCreate(
                    [
                        'ambient_signal_adapter_id' => $model->ambient_signal_adapter_id,
                        'external_event_id' => $event['external_event_id'],
                    ],
                    [
                        'event_uuid' => $event['event_uuid'] ?? (string) Str::uuid(),
                        'signal_type' => $event['signal_type'],
                        'occurred_at' => $event['occurred_at'],
                        'location_code' => $event['location_code'] ?? null,
                        'facility_space_id' => $location['facility_space_id'] ?? null,
                        'subject_ref_hash' => $event['subject_ref_hash'] ?? null,
                        'confidence_score' => $confidence,
                        'confidence_level' => $this->confidenceLevel($confidence),
                        'normalized_payload' => [
                            'adapter_key' => $definition['adapter_key'],
                            'source_type' => $definition['source_type'],
                            'location_code' => $event['location_code'] ?? null,
                            'signal_quality' => $event['signal_quality'] ?? null,
                            'latency_ms' => $event['latency_ms'] ?? null,
                            'confidence_factors' => $this->confidenceFactors((float) $definition['base_confidence'], $event),
                        ] + ($event['payload'] ?? []),
                        'raw_payload' => $event['raw_payload'] ?? $event,
                        'linked_flow_event_id' => $event['linked_flow_event_id'] ?? null,
                    ],
                );
            }
        }
    }

    /** @return array<int,AmbientSignalAdapter> */
    private function fixtureAdapters(): array
    {
        $now = now();

        return [
            new FixtureAmbientSignalAdapter([
                'adapter_key' => 'fixture_rtls',
                'label' => 'RTLS Location Adapter',
                'source_type' => 'rtls',
                'base_confidence' => 0.9200,
                'capabilities' => ['location_presence', 'dwell_time', 'zone_transition'],
            ], [[
                'external_event_id' => 'rtls-demo-presence-001',
                'signal_type' => 'zone_presence',
                'occurred_at' => $now->copy()->subMinutes(3),
                'location_code' => 'TICU-B001',
                'subject_ref_hash' => hash('sha256', 'fixture-patient-1'),
                'signal_quality' => 0.96,
                'latency_ms' => 800,
                'payload' => ['zone' => 'bedside', 'dwell_seconds' => 420],
            ]]),
            new FixtureAmbientSignalAdapter([
                'adapter_key' => 'fixture_room_sensor',
                'label' => 'Room Sensor Adapter',
                'source_type' => 'room_sensor',
                'base_confidence' => 0.8400,
                'capabilities' => ['room_occupancy', 'bed_exit', 'environmental_state'],
            ], [[
                'external_event_id' => 'room-demo-occupied-001',
                'signal_type' => 'room_occupied',
                'occurred_at' => $now->copy()->subMinutes(4),
                'location_code' => 'TICU-R001',
                'signal_quality' => 0.90,
                'latency_ms' => 1500,
                'payload' => ['occupancy_state' => 'occupied', 'motion_count' => 7],
            ]]),
            new FixtureAmbientSignalAdapter([
                'adapter_key' => 'fixture_nurse_call',
                'label' => 'Nurse Call Adapter',
                'source_type' => 'nurse_call',
                'base_confidence' => 0.7800,
                'capabilities' => ['call_active', 'call_cancelled', 'response_elapsed'],
            ], [[
                'external_event_id' => 'nurse-call-demo-001',
                'signal_type' => 'call_active',
                'occurred_at' => $now->copy()->subMinutes(2),
                'location_code' => 'TICU-R001',
                'signal_quality' => 0.86,
                'latency_ms' => 2200,
                'payload' => ['call_type' => 'assistance', 'priority' => 'routine'],
            ]]),
            new FixtureAmbientSignalAdapter([
                'adapter_key' => 'fixture_or_milestone',
                'label' => 'OR Milestone Adapter',
                'source_type' => 'or_milestone',
                'base_confidence' => 0.8800,
                'capabilities' => ['patient_in_room', 'procedure_start', 'patient_out_room', 'pacu_arrival'],
            ], [[
                'external_event_id' => 'or-demo-out-room-001',
                'signal_type' => 'patient_out_room',
                'occurred_at' => $now->copy()->subMinutes(6),
                'location_code' => 'OR-01',
                'signal_quality' => 0.91,
                'latency_ms' => 1200,
                'payload' => ['case_ref_hash' => hash('sha256', 'fixture-case-1'), 'milestone' => 'out_of_room'],
            ]]),
        ];
    }

    /** @param array<string,mixed> $event */
    private function scoreConfidence(float $baseConfidence, array $event): float
    {
        $quality = (float) ($event['signal_quality'] ?? 0.85);
        $latencyMs = (int) ($event['latency_ms'] ?? 3000);
        $latencyFactor = $latencyMs <= 1000 ? 1.0 : ($latencyMs <= 3000 ? 0.96 : 0.90);
        $locationFactor = empty($event['location_code']) ? 0.90 : 1.0;

        return round(min(0.99, max(0.1, $baseConfidence * $quality * $latencyFactor * $locationFactor)), 4);
    }

    /** @param array<string,mixed> $event */
    private function confidenceFactors(float $baseConfidence, array $event): array
    {
        return [
            'base_confidence' => $baseConfidence,
            'signal_quality' => (float) ($event['signal_quality'] ?? 0.85),
            'latency_ms' => (int) ($event['latency_ms'] ?? 3000),
            'location_present' => ! empty($event['location_code']),
        ];
    }

    private function confidenceLevel(float $score): string
    {
        return match (true) {
            $score >= 0.85 => 'high',
            $score >= 0.70 => 'medium',
            default => 'low',
        };
    }

    /** @return array<string,mixed> */
    private function serializeEvent(AmbientSignalEvent $event): array
    {
        return [
            'ambientSignalEventId' => $event->ambient_signal_event_id,
            'eventUuid' => $event->event_uuid,
            'adapterKey' => $event->adapter?->adapter_key,
            'adapterLabel' => $event->adapter?->label,
            'sourceType' => $event->adapter?->source_type,
            'signalType' => $event->signal_type,
            'occurredAtIso' => $event->occurred_at?->toIso8601String(),
            'locationCode' => $event->location_code,
            'facilitySpaceId' => $event->facility_space_id,
            'confidenceScore' => (float) $event->confidence_score,
            'confidenceLevel' => $event->confidence_level,
            'payload' => $event->normalized_payload ?? [],
            'linkedFlowEventId' => $event->linked_flow_event_id,
        ];
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('flow_realtime.ambient_signal_adapters')
            && Schema::hasTable('flow_realtime.ambient_signal_events');
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'generated_at' => Carbon::now()->toJSON(),
            'summary' => [
                'adapterCount' => 0,
                'enabledAdapterCount' => 0,
                'eventCount' => 0,
                'averageConfidence' => 0,
                'confidenceLevel' => 'low',
            ],
            'adapters' => [],
            'events' => [],
        ];
    }
}
