<?php

namespace App\Services\Ops;

use App\Models\Ops\OperationsEdge;
use App\Models\Ops\OperationsNode;
use App\Models\Ops\StateSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationsGraphProjector
{
    private const PROJECTOR = 'operations_graph_v1';

    /** @var array<string,int> */
    private array $nodeIdsByKey = [];

    public function rebuild(): StateSnapshot
    {
        return DB::transaction(function (): StateSnapshot {
            $this->nodeIdsByKey = [];

            OperationsEdge::query()->delete();
            OperationsNode::query()->update(['is_active' => false]);

            $latestCensus = $this->latestCensusByUnit();

            $this->projectLocations();
            $this->projectServices();
            $this->projectRooms();
            $this->projectUnits($latestCensus);
            $this->projectBeds();
            $this->projectEncounters();
            $this->projectEdVisits();
            $this->projectOrCases();
            $this->projectBedRequests();
            $this->projectTransportRequests();
            $this->projectEvsRequests();
            $this->projectBarriers();
            $this->projectFacilities();
            $this->projectTransfers();

            return $this->captureSnapshot();
        });
    }

    public function serializeSnapshot(StateSnapshot $snapshot): array
    {
        $payload = $snapshot->state_payload ?? [];

        return [
            'state_snapshot_id' => $snapshot->state_snapshot_id,
            'snapshot_uuid' => $snapshot->snapshot_uuid,
            'scope_type' => $snapshot->scope_type,
            'scope_key' => $snapshot->scope_key,
            'captured_at' => $snapshot->captured_at?->toISOString(),
            'node_count' => $snapshot->node_count,
            'edge_count' => $snapshot->edge_count,
            'state_hash' => $snapshot->state_hash,
            'by_type' => $payload['by_type'] ?? [],
            'edge_types' => $payload['edge_types'] ?? [],
            'nodes' => $payload['nodes'] ?? [],
        ];
    }

    public function serializeNodeTimeline(OperationsNode $node): array
    {
        $incoming = $node->incomingEdges()
            ->with('fromNode')
            ->orderBy('edge_type')
            ->get()
            ->map(fn (OperationsEdge $edge): array => $this->serializeEdge($edge, 'incoming'))
            ->values()
            ->all();

        $outgoing = $node->outgoingEdges()
            ->with('toNode')
            ->orderBy('edge_type')
            ->get()
            ->map(fn (OperationsEdge $edge): array => $this->serializeEdge($edge, 'outgoing'))
            ->values()
            ->all();

        return [
            'node' => $this->serializeNode($node),
            'incoming_edges' => $incoming,
            'outgoing_edges' => $outgoing,
            'timeline' => $this->buildSourceTimeline($node),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function latestCensusByUnit(): array
    {
        if (! Schema::hasTable('prod.census_snapshots')) {
            return [];
        }

        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT ON (unit_id)
                unit_id,
                captured_at,
                staffed_beds,
                occupied,
                available,
                blocked,
                acuity_adjusted_capacity
            FROM prod.census_snapshots
            ORDER BY unit_id, captured_at DESC
        SQL);

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[(int) $row->unit_id] = [
                'captured_at' => $row->captured_at,
                'staffed_beds' => (int) $row->staffed_beds,
                'occupied' => (int) $row->occupied,
                'available' => (int) $row->available,
                'blocked' => (int) $row->blocked,
                'acuity_adjusted_capacity' => (int) $row->acuity_adjusted_capacity,
            ];
        }

        return $byUnit;
    }

    private function projectLocations(): void
    {
        if (! Schema::hasTable('prod.locations')) {
            return;
        }

        DB::table('prod.locations')
            ->where('is_deleted', false)
            ->orderBy('location_id')
            ->get()
            ->each(function (object $location): void {
                $this->upsertNode(
                    type: 'location',
                    id: (string) $location->location_id,
                    displayName: $location->name,
                    sourceTable: 'locations',
                    status: ($location->active_status ?? true) ? 'active' : 'inactive',
                    state: [
                        'abbreviation' => $location->abbreviation,
                        'type' => $location->type,
                        'pos_type' => $location->pos_type,
                    ],
                    metadata: $this->facilityMetadata($location)
                );
            });
    }

    private function projectServices(): void
    {
        if (! Schema::hasTable('prod.services')) {
            return;
        }

        DB::table('prod.services')
            ->where('is_deleted', false)
            ->orderBy('service_id')
            ->get()
            ->each(function (object $service): void {
                $this->upsertNode(
                    type: 'service',
                    id: (string) $service->service_id,
                    displayName: $service->name,
                    sourceTable: 'services',
                    status: ($service->active_status ?? true) ? 'active' : 'inactive',
                    state: ['code' => $service->code ?? null],
                );
            });
    }

    private function projectRooms(): void
    {
        if (! Schema::hasTable('prod.rooms')) {
            return;
        }

        DB::table('prod.rooms')
            ->where('is_deleted', false)
            ->orderBy('room_id')
            ->get()
            ->each(function (object $room): void {
                $roomNode = $this->upsertNode(
                    type: 'room',
                    id: (string) $room->room_id,
                    displayName: $room->name,
                    sourceTable: 'rooms',
                    status: ($room->active_status ?? true) ? 'active' : 'inactive',
                    state: ['type' => $room->type],
                    metadata: $this->facilityMetadata($room)
                );

                if (! empty($room->location_id)) {
                    $this->addEdge($roomNode, "location:{$room->location_id}", 'located_in');
                }
            });
    }

    /** @param array<int,array<string,mixed>> $latestCensus */
    private function projectUnits(array $latestCensus): void
    {
        if (! Schema::hasTable('prod.units')) {
            return;
        }

        DB::table('prod.units')
            ->where('is_deleted', false)
            ->orderBy('unit_id')
            ->get()
            ->each(function (object $unit) use ($latestCensus): void {
                $census = $latestCensus[(int) $unit->unit_id] ?? null;
                $state = [
                    'type' => $unit->type,
                    'abbreviation' => $unit->abbreviation,
                    'staffed_bed_count' => (int) $unit->staffed_bed_count,
                    'ratio_floor' => (int) $unit->ratio_floor,
                    'access_standard_minutes' => (int) $unit->access_standard_minutes,
                    'latest_census' => $census,
                ];

                $this->upsertNode(
                    type: 'unit',
                    id: (string) $unit->unit_id,
                    displayName: $unit->name,
                    sourceTable: 'units',
                    status: 'active',
                    state: $state,
                    metadata: $this->facilityMetadata($unit),
                    observedAt: $census['captured_at'] ?? null,
                );
            });
    }

    private function projectBeds(): void
    {
        if (! Schema::hasTable('prod.beds')) {
            return;
        }

        DB::table('prod.beds')
            ->where('is_deleted', false)
            ->orderBy('bed_id')
            ->get()
            ->each(function (object $bed): void {
                $bedNode = $this->upsertNode(
                    type: 'bed',
                    id: (string) $bed->bed_id,
                    displayName: $bed->label,
                    sourceTable: 'beds',
                    status: $bed->status,
                    state: [
                        'unit_id' => (int) $bed->unit_id,
                        'bed_type' => $bed->bed_type,
                        'isolation_capable' => (bool) $bed->isolation_capable,
                    ],
                    metadata: $this->facilityMetadata($bed)
                );

                $this->addEdge("unit:{$bed->unit_id}", $bedNode, 'contains_bed');
            });
    }

    private function projectEncounters(): void
    {
        if (! Schema::hasTable('prod.encounters')) {
            return;
        }

        DB::table('prod.encounters')
            ->where('is_deleted', false)
            ->orderBy('encounter_id')
            ->get()
            ->each(function (object $encounter): void {
                $encounterNode = $this->upsertNode(
                    type: 'encounter',
                    id: (string) $encounter->encounter_id,
                    displayName: $encounter->patient_ref,
                    sourceTable: 'encounters',
                    status: $encounter->status,
                    state: [
                        'patient_ref' => $encounter->patient_ref,
                        'unit_id' => $encounter->unit_id ? (int) $encounter->unit_id : null,
                        'bed_id' => $encounter->bed_id ? (int) $encounter->bed_id : null,
                        'admitted_at' => $encounter->admitted_at,
                        'expected_discharge_date' => $encounter->expected_discharge_date,
                        'acuity_tier' => (int) $encounter->acuity_tier,
                        'discharged_at' => $encounter->discharged_at,
                    ],
                    observedAt: $encounter->updated_at,
                );

                if (! empty($encounter->unit_id)) {
                    $this->addEdge($encounterNode, "unit:{$encounter->unit_id}", 'assigned_to_unit');
                }
                if (! empty($encounter->bed_id)) {
                    $this->addEdge($encounterNode, "bed:{$encounter->bed_id}", 'occupies_bed');
                }
            });
    }

    private function projectEdVisits(): void
    {
        if (! Schema::hasTable('prod.ed_visits')) {
            return;
        }

        DB::table('prod.ed_visits')
            ->where('is_deleted', false)
            ->orderBy('ed_visit_id')
            ->get()
            ->each(function (object $visit): void {
                $visitNode = $this->upsertNode(
                    type: 'ed_visit',
                    id: (string) $visit->ed_visit_id,
                    displayName: $visit->patient_ref,
                    sourceTable: 'ed_visits',
                    status: $visit->disposition ?? 'active',
                    state: [
                        'patient_ref' => $visit->patient_ref,
                        'arrived_at' => $visit->arrived_at,
                        'triaged_at' => $visit->triaged_at,
                        'esi_level' => $visit->esi_level ? (int) $visit->esi_level : null,
                        'provider_seen_at' => $visit->provider_seen_at,
                        'disposition' => $visit->disposition,
                        'admit_decision_at' => $visit->admit_decision_at,
                        'bed_assigned_at' => $visit->bed_assigned_at,
                        'departed_at' => $visit->departed_at,
                    ],
                    observedAt: $visit->updated_at,
                );

                if (! empty($visit->unit_id)) {
                    $this->addEdge($visitNode, "unit:{$visit->unit_id}", 'admits_to_unit');
                }
            });
    }

    private function projectOrCases(): void
    {
        if (! Schema::hasTable('prod.or_cases')) {
            return;
        }

        DB::table('prod.or_cases')
            ->where('is_deleted', false)
            ->orderBy('case_id')
            ->get()
            ->each(function (object $case): void {
                $procedureName = $this->columnValue($case, 'procedure_name');
                $caseServiceId = $this->columnValue($case, 'case_service_id');
                $roomId = $this->columnValue($case, 'room_id');
                $journeyProgress = $this->columnValue($case, 'journey_progress');

                $caseNode = $this->upsertNode(
                    type: 'or_case',
                    id: (string) $case->case_id,
                    displayName: $procedureName ?: "OR Case {$case->case_id}",
                    sourceTable: 'or_cases',
                    status: (string) $case->status_id,
                    state: [
                        'patient_id' => $case->patient_id,
                        'surgery_date' => $case->surgery_date,
                        'room_id' => $roomId ? (int) $roomId : null,
                        'case_service_id' => $caseServiceId ? (int) $caseServiceId : null,
                        'scheduled_start_time' => $case->scheduled_start_time,
                        'scheduled_duration' => $case->scheduled_duration ? (int) $case->scheduled_duration : null,
                        'safety_status' => $this->columnValue($case, 'safety_status'),
                        'journey_progress' => $journeyProgress ? (int) $journeyProgress : null,
                    ],
                    observedAt: $case->updated_at,
                );

                if (! empty($roomId)) {
                    $this->addEdge($caseNode, "room:{$roomId}", 'scheduled_in_room');
                }
                if (! empty($caseServiceId)) {
                    $this->addEdge($caseNode, "service:{$caseServiceId}", 'served_by');
                }
            });
    }

    private function projectBedRequests(): void
    {
        if (! Schema::hasTable('prod.bed_requests')) {
            return;
        }

        DB::table('prod.bed_requests')
            ->where('is_deleted', false)
            ->orderBy('bed_request_id')
            ->get()
            ->each(function (object $request): void {
                $requestNode = $this->upsertNode(
                    type: 'bed_request',
                    id: (string) $request->bed_request_id,
                    displayName: "{$request->source} bed request {$request->bed_request_id}",
                    sourceTable: 'bed_requests',
                    status: $request->status,
                    state: [
                        'patient_ref' => $request->patient_ref,
                        'source' => $request->source,
                        'service' => $request->service,
                        'acuity_tier' => (int) $request->acuity_tier,
                        'isolation_required' => $request->isolation_required,
                        'required_unit_type' => $request->required_unit_type,
                    ],
                    observedAt: $request->updated_at,
                );

                $this->addEdgeToEncounterByPatient($requestNode, $request->patient_ref, 'requests_bed_for');
            });
    }

    private function projectTransportRequests(): void
    {
        if (! Schema::hasTable('prod.transport_requests')) {
            return;
        }

        DB::table('prod.transport_requests')
            ->where('is_deleted', false)
            ->orderBy('transport_request_id')
            ->get()
            ->each(function (object $request): void {
                $requestNode = $this->upsertNode(
                    type: 'transport_request',
                    id: (string) $request->transport_request_id,
                    displayName: "{$request->origin} to {$request->destination}",
                    sourceTable: 'transport_requests',
                    status: $request->status,
                    state: [
                        'request_uuid' => $request->request_uuid,
                        'request_type' => $request->request_type,
                        'priority' => $request->priority,
                        'patient_ref' => $request->patient_ref,
                        'encounter_ref' => $request->encounter_ref,
                        'origin' => $request->origin,
                        'destination' => $request->destination,
                        'transport_mode' => $request->transport_mode,
                        'needed_at' => $request->needed_at,
                    ],
                    observedAt: $request->updated_at,
                );

                $this->addEdgeToEncounterByPatient($requestNode, $request->patient_ref, 'moves_patient_for');
            });
    }

    private function projectEvsRequests(): void
    {
        if (! Schema::hasTable('prod.evs_requests')) {
            return;
        }

        DB::table('prod.evs_requests')
            ->where('is_deleted', false)
            ->orderBy('evs_request_id')
            ->get()
            ->each(function (object $request): void {
                $requestNode = $this->upsertNode(
                    type: 'evs_request',
                    id: (string) $request->evs_request_id,
                    displayName: "{$request->location_label} {$request->request_type}",
                    sourceTable: 'evs_requests',
                    status: $request->status,
                    state: [
                        'request_uuid' => $request->request_uuid,
                        'request_type' => $request->request_type,
                        'priority' => $request->priority,
                        'room_id' => $request->room_id ? (int) $request->room_id : null,
                        'bed_id' => $request->bed_id ? (int) $request->bed_id : null,
                        'unit_id' => $request->unit_id ? (int) $request->unit_id : null,
                        'patient_ref' => $request->patient_ref,
                        'encounter_ref' => $request->encounter_ref,
                        'location_label' => $request->location_label,
                        'turn_type' => $request->turn_type,
                        'isolation_required' => (bool) $request->isolation_required,
                        'needed_at' => $request->needed_at,
                    ],
                    observedAt: $request->updated_at,
                );

                if (! empty($request->bed_id)) {
                    $this->addEdge($requestNode, "bed:{$request->bed_id}", 'cleans_bed');
                }
                if (! empty($request->room_id)) {
                    $this->addEdge($requestNode, "room:{$request->room_id}", 'cleans_room');
                }
                if (! empty($request->unit_id)) {
                    $this->addEdge($requestNode, "unit:{$request->unit_id}", 'supports_unit');
                }

                $this->addEdgeToEncounterByPatient($requestNode, $request->patient_ref, 'cleans_for_encounter');
            });
    }

    private function projectBarriers(): void
    {
        if (! Schema::hasTable('prod.barriers')) {
            return;
        }

        DB::table('prod.barriers')
            ->where('is_deleted', false)
            ->orderBy('barrier_id')
            ->get()
            ->each(function (object $barrier): void {
                $barrierNode = $this->upsertNode(
                    type: 'barrier',
                    id: (string) $barrier->barrier_id,
                    displayName: $barrier->description ?: "{$barrier->category} barrier",
                    sourceTable: 'barriers',
                    status: $barrier->status,
                    state: [
                        'encounter_id' => $barrier->encounter_id ? (int) $barrier->encounter_id : null,
                        'unit_id' => $barrier->unit_id ? (int) $barrier->unit_id : null,
                        'category' => $barrier->category,
                        'reason_code' => $barrier->reason_code,
                        'owner' => $barrier->owner,
                        'opened_at' => $barrier->opened_at,
                        'resolved_at' => $barrier->resolved_at,
                    ],
                    observedAt: $barrier->updated_at,
                );

                if (! empty($barrier->encounter_id)) {
                    $this->addEdge($barrierNode, "encounter:{$barrier->encounter_id}", 'blocks_encounter');
                }
                if (! empty($barrier->unit_id)) {
                    $this->addEdge($barrierNode, "unit:{$barrier->unit_id}", 'impacts_unit');
                }
            });
    }

    /**
     * Layer 1: project every hosp_org.facilities row as a facility node
     * (canonical_key = facility:{facility_key}), guarded so the projector degrades
     * gracefully before the deployment schema is migrated.
     */
    private function projectFacilities(): void
    {
        if (! Schema::hasTable('hosp_org.facilities')) {
            return;
        }

        DB::table('hosp_org.facilities')
            ->orderBy('facility_id')
            ->get()
            ->each(function (object $facility): void {
                $this->upsertNode(
                    type: 'facility',
                    id: (string) $facility->facility_key,
                    displayName: (string) ($facility->facility_name ?? $facility->facility_key),
                    sourceTable: 'facilities',
                    status: ($facility->is_active ?? true) ? 'active' : 'inactive',
                    state: [
                        'facility_key' => $facility->facility_key,
                        'idn_role' => $facility->idn_role,
                        'state' => $facility->state ?? null,
                        'county' => $facility->county ?? null,
                        'trauma_level_adult' => $facility->trauma_level_adult ?? null,
                        'neonatal_level' => $facility->neonatal_level ?? null,
                        'cad_facility_code' => $facility->cad_facility_code ?? null,
                        'review_status' => $facility->review_status ?? null,
                    ],
                    metadata: ['organization_id' => (int) $facility->organization_id],
                    sourceSchema: 'hosp_org',
                );
            });
    }

    /**
     * Project hosp_org.transfer_relationships as directed transfers_to edges. Because
     * ops.edges is uniquely keyed on (from, to, edge_type), rows for the same facility
     * pair across several service lines are aggregated into ONE edge, with the service
     * lines / transport modes carried in metadata and weight = the fastest typical_minutes.
     */
    private function projectTransfers(): void
    {
        if (! Schema::hasTable('hosp_org.transfer_relationships')) {
            return;
        }

        $rows = DB::table('hosp_org.transfer_relationships')
            ->where('is_active', true)
            ->orderBy('transfer_relationship_id')
            ->get();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $fromKey = $row->source_facility_key;
            if (! $fromKey) {
                continue; // a transfer must originate from an internal facility node
            }

            $toRef = $row->destination_facility_key
                ?: ($row->destination_external_name ? 'ext:'.Str::slug($row->destination_external_name) : null);
            if ($toRef === null) {
                continue;
            }

            $groupKey = $fromKey.'->'.$toRef;
            $groups[$groupKey] ??= [
                'from_key' => $fromKey,
                'dest_facility_key' => $row->destination_facility_key,
                'dest_external_name' => $row->destination_external_name,
                'rows' => [],
            ];
            $groups[$groupKey]['rows'][] = $row;
        }

        foreach ($groups as $group) {
            $fromNodeKey = "facility:{$group['from_key']}";
            if (! isset($this->nodeIdsByKey[$fromNodeKey])) {
                continue;
            }

            $toNodeKey = $this->resolveTransferTargetNode($group);
            if ($toNodeKey === null) {
                continue;
            }

            $serviceLines = [];
            $transportModes = [];
            $minutes = [];
            $ids = [];
            $external = false;
            foreach ($group['rows'] as $row) {
                if ($row->service_line_code) {
                    $serviceLines[] = $row->service_line_code;
                }
                if ($row->transport_mode) {
                    $transportModes[] = $row->transport_mode;
                }
                if ($row->typical_minutes !== null) {
                    $minutes[] = (int) $row->typical_minutes;
                }
                $external = $external || (bool) $row->is_external_partner;
                $ids[] = (int) $row->transfer_relationship_id;
            }

            $weight = $minutes === [] ? 1 : min($minutes);

            $this->addEdge(
                $fromNodeKey,
                $toNodeKey,
                'transfers_to',
                [
                    'service_lines' => array_values(array_unique($serviceLines)),
                    'transport_modes' => array_values(array_unique($transportModes)),
                    'is_external_partner' => $external,
                    'transfer_count' => count($group['rows']),
                    'transfer_relationship_ids' => $ids,
                    'typical_minutes' => $weight,
                ],
                weight: $weight,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function resolveTransferTargetNode(array $group): ?string
    {
        if ($group['dest_facility_key']) {
            $key = "facility:{$group['dest_facility_key']}";

            return isset($this->nodeIdsByKey[$key]) ? $key : null;
        }

        if ($group['dest_external_name']) {
            $slug = Str::slug((string) $group['dest_external_name']);
            $this->upsertNode(
                type: 'external_facility',
                id: $slug,
                displayName: (string) $group['dest_external_name'],
                sourceTable: 'transfer_relationships',
                status: 'active',
                state: ['name' => $group['dest_external_name'], 'is_external' => true],
                sourceSchema: 'hosp_org',
            );

            return "external_facility:{$slug}";
        }

        return null;
    }

    private function upsertNode(
        string $type,
        string $id,
        string $displayName,
        string $sourceTable,
        ?string $status,
        array $state,
        array $metadata = [],
        mixed $observedAt = null,
        string $sourceSchema = 'prod',
    ): OperationsNode {
        $canonicalKey = "{$type}:{$id}";
        $node = OperationsNode::firstOrNew(['canonical_key' => $canonicalKey]);

        if (! $node->exists) {
            $node->node_uuid = (string) Str::uuid();
        }

        $node->fill([
            'node_type' => $type,
            'display_name' => $displayName,
            'source_schema' => $sourceSchema,
            'source_table' => $sourceTable,
            'source_pk' => $id,
            'status' => $status,
            'source_priority' => 100,
            'current_state' => $this->cleanArray($state),
            'metadata' => array_merge(['projector' => self::PROJECTOR], $this->cleanArray($metadata)),
            'last_observed_at' => $observedAt ? Carbon::parse($observedAt) : now(),
            'is_active' => true,
        ]);
        $node->save();

        $this->nodeIdsByKey[$canonicalKey] = (int) $node->graph_node_id;

        return $node;
    }

    private function addEdge(OperationsNode|string $from, OperationsNode|string $to, string $type, array $metadata = [], int|float $weight = 1): void
    {
        $fromId = $from instanceof OperationsNode ? (int) $from->graph_node_id : ($this->nodeIdsByKey[$from] ?? null);
        $toId = $to instanceof OperationsNode ? (int) $to->graph_node_id : ($this->nodeIdsByKey[$to] ?? null);

        if (! $fromId || ! $toId || $fromId === $toId) {
            return;
        }

        OperationsEdge::create([
            'edge_uuid' => (string) Str::uuid(),
            'from_node_id' => $fromId,
            'to_node_id' => $toId,
            'edge_type' => $type,
            'weight' => $weight,
            'metadata' => array_merge(['projector' => self::PROJECTOR], $this->cleanArray($metadata)),
            'valid_from' => now(),
            'is_active' => true,
        ]);
    }

    private function addEdgeToEncounterByPatient(OperationsNode $from, ?string $patientRef, string $type): void
    {
        if (! $patientRef || ! Schema::hasTable('prod.encounters')) {
            return;
        }

        $encounterId = DB::table('prod.encounters')
            ->where('patient_ref', $patientRef)
            ->where('is_deleted', false)
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('encounter_id')
            ->value('encounter_id');

        if ($encounterId) {
            $this->addEdge($from, "encounter:{$encounterId}", $type);
        }
    }

    private function captureSnapshot(): StateSnapshot
    {
        $nodes = OperationsNode::query()
            ->where('is_active', true)
            ->orderBy('node_type')
            ->orderBy('canonical_key')
            ->get();

        $edges = OperationsEdge::query()
            ->where('is_active', true)
            ->get();

        $byType = $nodes->groupBy('node_type')->map->count()->sortKeys()->all();
        $edgeTypes = $edges->groupBy('edge_type')->map->count()->sortKeys()->all();
        $nodeSummaries = $nodes
            ->map(fn (OperationsNode $node): array => [
                'graph_node_id' => $node->graph_node_id,
                'node_type' => $node->node_type,
                'canonical_key' => $node->canonical_key,
                'display_name' => $node->display_name,
                'status' => $node->status,
            ])
            ->values()
            ->all();

        $payload = [
            'by_type' => $byType,
            'edge_types' => $edgeTypes,
            'nodes' => $nodeSummaries,
        ];

        return StateSnapshot::create([
            'snapshot_uuid' => (string) Str::uuid(),
            'scope_type' => 'hospital',
            'scope_key' => null,
            'captured_at' => now(),
            'node_count' => $nodes->count(),
            'edge_count' => $edges->count(),
            'state_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'state_payload' => $payload,
            'metadata' => [
                'projector' => self::PROJECTOR,
                'source' => 'prod operational tables',
            ],
        ]);
    }

    private function serializeNode(OperationsNode $node): array
    {
        return [
            'graph_node_id' => $node->graph_node_id,
            'node_uuid' => $node->node_uuid,
            'node_type' => $node->node_type,
            'canonical_key' => $node->canonical_key,
            'display_name' => $node->display_name,
            'source_schema' => $node->source_schema,
            'source_table' => $node->source_table,
            'source_pk' => $node->source_pk,
            'status' => $node->status,
            'current_state' => $node->current_state ?? [],
            'metadata' => $node->metadata ?? [],
            'last_observed_at' => $node->last_observed_at?->toISOString(),
            'is_active' => $node->is_active,
        ];
    }

    private function serializeEdge(OperationsEdge $edge, string $direction): array
    {
        $other = $direction === 'incoming' ? $edge->fromNode : $edge->toNode;

        return [
            'graph_edge_id' => $edge->graph_edge_id,
            'edge_type' => $edge->edge_type,
            'direction' => $direction,
            'other_node' => $other ? [
                'graph_node_id' => $other->graph_node_id,
                'node_type' => $other->node_type,
                'canonical_key' => $other->canonical_key,
                'display_name' => $other->display_name,
                'status' => $other->status,
            ] : null,
            'metadata' => $edge->metadata ?? [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function buildSourceTimeline(OperationsNode $node): array
    {
        return [
            [
                'event_type' => 'source_projection',
                'source_table' => "{$node->source_schema}.{$node->source_table}",
                'source_pk' => $node->source_pk,
                'observed_at' => $node->last_observed_at?->toISOString(),
                'status' => $node->status,
            ],
        ];
    }

    private function facilityMetadata(object $row): array
    {
        return property_exists($row, 'facility_space_id') && $row->facility_space_id
            ? ['facility_space_id' => (int) $row->facility_space_id]
            : [];
    }

    private function columnValue(object $row, string $column, mixed $default = null): mixed
    {
        return property_exists($row, $column) ? $row->{$column} : $default;
    }

    private function cleanArray(array $value): array
    {
        return array_filter(
            $value,
            fn ($item): bool => $item !== null,
        );
    }
}
