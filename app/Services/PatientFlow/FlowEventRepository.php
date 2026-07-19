<?php

namespace App\Services\PatientFlow;

use App\Models\Bed;
use App\Models\PatientFlow\FlowEncounter;
use App\Models\PatientFlow\FlowEvent;
use App\Models\PatientFlow\PatientIdentity;
use App\Models\Unit;
use App\Support\Hospital\HospitalManifest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FlowEventRepository
{
    /** @var array<string, int>|null */
    private ?array $unitIdsByCode = null;

    /** @var array<int, int>|null */
    private ?array $unitIdsBySpace = null;

    public function __construct(private readonly FacilitySpaceLocationResolver $locations) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function upsertNormalizedEvent(
        array $event,
        ?int $sourceId = null,
        ?int $inboundMessageId = null,
        ?int $canonicalEventId = null,
        string $facilityCode = 'ZEPHYRUS-500',
    ): FlowEvent {
        $toLocation = $this->locations->resolve($event['to_location'] ?? null, $facilityCode);
        $fromLocation = $this->locations->resolve($event['from_location'] ?? null, $facilityCode);
        $serviceLine = $event['service_line'] ?? $toLocation['service_line'] ?? null;
        $cancellation = $event['cancellation_of_event_id'] ?? null;

        if ($cancellation && ! FlowEvent::query()->whereKey($cancellation)->exists()) {
            $cancellation = null;
        }

        PatientIdentity::query()->updateOrCreate(
            ['patient_ref' => $event['patient_id']],
            [
                'patient_display_ref' => $event['patient_display_id'],
                'identifier_hash' => $event['patient_id'],
                'deidentified' => (bool) ($event['deidentified'] ?? true),
                'metadata' => [],
            ],
        );

        FlowEncounter::query()->updateOrCreate(
            ['encounter_ref' => $event['encounter_id']],
            [
                'patient_ref' => $event['patient_id'],
                'patient_class' => $event['patient_class'] ?? null,
                'service_line' => $serviceLine,
                'encounter_status' => $event['fhir_encounter_status'] ?? 'in-progress',
                'started_at' => $event['occurred_at'],
                'ended_at' => ($event['event_type'] ?? null) === 'discharge' ? $event['occurred_at'] : null,
                'metadata' => [],
            ],
        );

        return FlowEvent::query()->updateOrCreate(
            ['flow_event_id' => $event['event_id']],
            [
                'source_id' => $sourceId,
                'inbound_message_id' => $inboundMessageId,
                'canonical_event_id' => $canonicalEventId,
                'event_category' => $event['event_category'],
                'event_type' => $event['event_type'],
                'message_type' => $event['message_type'] ?? null,
                'trigger_event' => $event['trigger_event'] ?? null,
                'patient_ref' => $event['patient_id'],
                'patient_display_ref' => $event['patient_display_id'],
                'encounter_ref' => $event['encounter_id'],
                'occurred_at' => $event['occurred_at'],
                'recorded_at' => $event['recorded_at'] ?? CarbonImmutable::now('UTC')->toJSON(),
                'from_source_location_code' => $event['from_location'] ?? null,
                'to_source_location_code' => $event['to_location'] ?? null,
                'from_facility_space_id' => $fromLocation['facility_space_id'] ?? null,
                'to_facility_space_id' => $toLocation['facility_space_id'] ?? null,
                'point_of_care' => $event['point_of_care'] ?? null,
                'room' => $event['room'] ?? null,
                'bed' => $event['bed'] ?? null,
                'patient_class' => $event['patient_class'] ?? null,
                'fhir_encounter_status' => $event['fhir_encounter_status'] ?? null,
                'fhir_encounter_class' => $event['fhir_encounter_class'] ?? null,
                'service_line' => $serviceLine,
                'priority' => $event['priority'] ?? null,
                'diagnosis_codes' => $event['diagnosis_codes'] ?? [],
                'order_codes' => $event['order_codes'] ?? [],
                'observation_codes' => $event['observation_codes'] ?? [],
                'medication_codes' => $event['medication_codes'] ?? [],
                'cancellation_of_event_id' => $cancellation,
                'raw_message_hash' => $event['raw_message_hash'] ?? null,
                'source_protocol' => $event['source_protocol'] ?? 'hl7v2',
                'deidentified' => (bool) ($event['deidentified'] ?? true),
                'metadata' => ($event['metadata'] ?? []) + [
                    'source_system' => $event['source_system'] ?? null,
                    'message_control_id' => $event['message_control_id'] ?? null,
                    'attending_provider_hash' => isset($event['attending_provider'])
                        ? FlowEventNormalizer::stableHash((string) $event['attending_provider'])
                        : null,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, FlowEvent>
     */
    public function filteredEvents(array $filters = []): Collection
    {
        $query = FlowEvent::query()
            ->with(['toFacilitySpace', 'fromFacilitySpace'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('flow_event_id');

        $this->applyFilters($query, $filters);

        $limit = min(max((int) ($filters['limit'] ?? 5000), 1), 20000);

        // Limit the newest matching rows in SQL, then restore chronological
        // replay order for state projection, trails, and the event stream.
        return $query->limit($limit)
            ->get()
            ->sort(function (FlowEvent $left, FlowEvent $right): int {
                $timeOrder = $left->occurred_at <=> $right->occurred_at;

                return $timeOrder !== 0
                    ? $timeOrder
                    : strcmp((string) $left->flow_event_id, (string) $right->flow_event_id);
            })
            ->values();
    }

    /**
     * @param  Collection<int, FlowEvent>  $events
     * @return list<array<string, mixed>>
     */
    public function serializeEvents(Collection $events): array
    {
        return $events->map(fn (FlowEvent $event): array => $this->serializeEvent($event))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeEvent(FlowEvent $event): array
    {
        $location = $this->locations->spaceToPayload($event->toFacilitySpace);
        $unitId = $this->unitIdForEvent($event, $location);

        return [
            'event_id' => $event->flow_event_id,
            'event_category' => $event->event_category,
            'event_type' => $event->event_type,
            'message_type' => $event->message_type,
            'trigger_event' => $event->trigger_event,
            'patient_id' => $event->patient_ref,
            'patient_display_id' => $event->patient_display_ref,
            'encounter_id' => $event->encounter_ref,
            'occurred_at' => $event->occurred_at?->toJSON(),
            'recorded_at' => $event->recorded_at?->toJSON(),
            'from_location' => $event->from_source_location_code,
            'to_location' => $event->to_source_location_code,
            'point_of_care' => $event->point_of_care,
            'room' => $event->room,
            'bed' => $event->bed,
            'patient_class' => $event->patient_class,
            'fhir_encounter_status' => $event->fhir_encounter_status,
            'fhir_encounter_class' => $event->fhir_encounter_class,
            'service_line' => $event->service_line ?: ($location['service_line'] ?? null),
            'priority' => $event->priority,
            'diagnosis_codes' => $event->diagnosis_codes ?? [],
            'order_codes' => $event->order_codes ?? [],
            'observation_codes' => $event->observation_codes ?? [],
            'medication_codes' => $event->medication_codes ?? [],
            'cancellation_of_event_id' => $event->cancellation_of_event_id,
            'raw_message_hash' => $event->raw_message_hash,
            'source_protocol' => $event->source_protocol,
            'deidentified' => $event->deidentified,
            'metadata' => $event->metadata ?? [],
            'facility_space_id' => $event->to_facility_space_id,
            'location_name' => $location['name'] ?? null,
            'location_category' => $location['category'] ?? null,
            'location_floor' => $location['floor'] ?? null,
            'location_service_line' => $location['service_line'] ?? null,
            'position_ft' => $location['position_ft'] ?? null,
            'position_m' => $location['position_m'] ?? null,
            'unit_code' => $location['unit_code'] ?? null,
            'unit_id' => $unitId,
        ];
    }

    /** @param array<string, mixed>|null $location */
    private function unitIdForEvent(FlowEvent $event, ?array $location): ?int
    {
        $this->loadUnitMaps();

        $unitCode = isset($location['unit_code']) && is_scalar($location['unit_code'])
            ? strtoupper(trim((string) $location['unit_code']))
            : null;
        if ($unitCode && isset($this->unitIdsByCode[$unitCode])) {
            return $this->unitIdsByCode[$unitCode];
        }

        $spaceId = $event->to_facility_space_id !== null ? (int) $event->to_facility_space_id : null;
        if ($spaceId !== null && isset($this->unitIdsBySpace[$spaceId])) {
            return $this->unitIdsBySpace[$spaceId];
        }

        // Scene-code prefix fallback: ED bays and other spaces without a
        // unit_code attribute lead with the unit's CAD prefix in their scene
        // code ('ED-TRIAGE-002' → 'ED'). Without this, every ED event carried
        // unit_id null and vanished under unit-depth lenses (2026-07-19).
        $locationCode = isset($location['location_code']) && is_scalar($location['location_code'])
            ? strtoupper(trim((string) $location['location_code']))
            : null;
        if ($locationCode !== null && $locationCode !== '') {
            $prefix = explode('-', $locationCode, 2)[0];
            if (isset($this->unitIdsByCode[$prefix])) {
                return $this->unitIdsByCode[$prefix];
            }
        }

        return null;
    }

    private function loadUnitMaps(): void
    {
        if ($this->unitIdsByCode !== null && $this->unitIdsBySpace !== null) {
            return;
        }

        $this->unitIdsByCode = [];
        $this->unitIdsBySpace = [];

        foreach (Unit::query()->where('is_deleted', false)->get(['unit_id', 'abbreviation', 'facility_space_id']) as $unit) {
            if ($unit->abbreviation) {
                $this->unitIdsByCode[strtoupper(trim((string) $unit->abbreviation))] = (int) $unit->unit_id;
            }

            if ($unit->facility_space_id !== null) {
                $this->unitIdsBySpace[(int) $unit->facility_space_id] = (int) $unit->unit_id;
            }
        }

        foreach (Bed::query()->where('is_deleted', false)->whereNotNull('facility_space_id')->get(['facility_space_id', 'unit_id']) as $bed) {
            $this->unitIdsBySpace[(int) $bed->facility_space_id] = (int) $bed->unit_id;
        }

        // Manifest CAD bridge: facility spaces carry the CAD vocabulary in
        // attributes.unit_code (MICU3, MS5B, TEL7A) while prod.units carries
        // operational abbreviations (MICU, 5E, 7E). The two never string-match,
        // which left ~78% of flow events with unit_id null — invisible under
        // any unit-depth lens (2026-07-19). The hospital manifest is the
        // authoritative abbr↔cad_code pairing.
        foreach ((new HospitalManifest)->units() as $manifestUnit) {
            $abbr = strtoupper(trim((string) ($manifestUnit['abbr'] ?? '')));
            $cad = strtoupper(trim((string) ($manifestUnit['cad_code'] ?? '')));
            if ($abbr === '' || $cad === '' || $cad === $abbr || ! isset($this->unitIdsByCode[$abbr])) {
                continue;
            }
            $this->unitIdsByCode[$cad] = $this->unitIdsByCode[$abbr];
        }
    }

    /**
     * @param  Builder<FlowEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', $filters['to']);
        }

        if (! empty($filters['patient'])) {
            $patient = $filters['patient'];
            $query->where(function (Builder $inner) use ($patient) {
                $inner->where('patient_ref', $patient)
                    ->orWhere('patient_display_ref', $patient);
            });
        }

        if (! empty($filters['category'])) {
            $query->where('event_category', $filters['category']);
        }

        if (! empty($filters['service_line'])) {
            $query->where('service_line', $filters['service_line']);
        }

        if (isset($filters['floor']) && $filters['floor'] !== '' && $filters['floor'] !== 'all') {
            $floor = (int) $filters['floor'];
            $query->whereHas('toFacilitySpace', fn (Builder $spaceQuery) => $spaceQuery->where('floor_number', $floor));
        }
    }
}
