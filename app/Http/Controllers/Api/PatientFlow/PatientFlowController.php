<?php

namespace App\Http\Controllers\Api\PatientFlow;

use App\Http\Controllers\Controller;
use App\Models\Barrier;
use App\Models\PatientFlow\FlowEvent;
use App\Services\Flow\FlowLensService;
use App\Services\PatientFlow\AmbientSignalService;
use App\Services\PatientFlow\FacilitySpaceLocationResolver;
use App\Services\PatientFlow\FhirBundleFactory;
use App\Services\PatientFlow\PatientFlowEventAccessService;
use App\Services\PatientFlow\PatientFlowOccupancyContextService;
use App\Services\PatientFlow\PatientFlowOccupancyHistoryService;
use App\Services\PatientFlow\PatientFlowScenarioRegistry;
use App\Services\PatientFlow\PatientStateProjector;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PatientFlowController extends Controller
{
    public function __construct(
        private readonly PatientFlowEventAccessService $eventAccess,
        private readonly FlowLensService $flowLens,
        private readonly FacilitySpaceLocationResolver $locations,
        private readonly PatientStateProjector $stateProjector,
        private readonly FhirBundleFactory $fhir,
        private readonly AmbientSignalService $ambientSignals,
        private readonly PatientFlowOccupancyContextService $occupancyContext,
        private readonly PatientFlowOccupancyHistoryService $occupancyHistory,
        private readonly PatientFlowScenarioRegistry $scenarios,
    ) {}

    public function summary(): JsonResponse
    {
        $eventCount = (int) DB::table('flow_core.flow_events')->count();
        $ambient = $this->ambientSignals->summary($this->facilityCode());
        $generatedAt = CarbonImmutable::now();
        $firstEventValue = DB::table('flow_core.flow_events')->min('occurred_at');
        $lastEventValue = DB::table('flow_core.flow_events')->max('occurred_at');
        $firstEventAt = $firstEventValue ? CarbonImmutable::parse((string) $firstEventValue)->toJSON() : null;
        $lastEventAt = $lastEventValue ? CarbonImmutable::parse((string) $lastEventValue)->toJSON() : null;
        $latestEvent = FlowEvent::query()
            ->with('source')
            ->orderByDesc('occurred_at')
            ->orderByDesc('flow_event_id')
            ->first();
        $source = $this->sourceEnvelope($latestEvent, $generatedAt);

        return response()->json([
            'messages' => (int) DB::table('raw.inbound_messages as messages')
                ->join('integration.sources as sources', 'sources.source_id', '=', 'messages.source_id')
                ->where('sources.source_key', 'synthetic-flow-ehr')
                ->count(),
            'normalized_events' => $eventCount,
            'patients' => (int) DB::table('flow_core.patient_identities')->count(),
            'locations' => count($this->locations->allNavigatorLocations($this->facilityCode())),
            'movement_events' => (int) DB::table('flow_core.flow_events')->where('event_category', 'movement')->count(),
            'clinical_context_events' => (int) DB::table('flow_core.flow_events')->where('event_category', '<>', 'movement')->count(),
            'min_occurred_at' => $firstEventAt,
            'max_occurred_at' => $lastEventAt,
            'live_events' => 0,
            'open_barriers' => (int) Barrier::query()->open()->count(),
            'ambient_signals' => $ambient['summary']['eventCount'],
            'ambient_confidence' => $ambient['summary']['averageConfidence'],
            'ambient_confidence_level' => $ambient['summary']['confidenceLevel'],
            'facility_code' => $this->facilityCode(),
            'model_url' => (string) config('facility_models.zep_500.model_url'),
            'tileset_url' => (string) config('facility_models.zep_500.tileset_url'),
            'source' => $source,
            'data_extent' => [
                'first_event_at' => $firstEventAt,
                'last_event_at' => $lastEventAt,
                'event_count' => $eventCount,
            ],
            'suggested_initial_time' => $lastEventAt,
            'generated_at' => $generatedAt->toJSON(),
        ]);
    }

    public function locations(): JsonResponse
    {
        return response()->json($this->attachOpsGraphNodes(
            $this->locations->allNavigatorLocations($this->facilityCode()),
        ));
    }

    public function events(Request $request): JsonResponse
    {
        try {
            return $this->patientJson($this->eventAccess->rows($request, $this->filters($request)));
        } catch (InvalidArgumentException $exception) {
            return $this->invalidPatientFilter($exception);
        }
    }

    public function tracks(Request $request): JsonResponse
    {
        try {
            $events = $this->eventAccess->rows($request, $this->filters($request));
        } catch (InvalidArgumentException $exception) {
            return $this->invalidPatientFilter($exception);
        }

        $tracks = [];
        foreach ($events as $event) {
            $tracks[$event['patient_id']][] = $event;
        }

        return $this->patientJson($tracks);
    }

    public function state(Request $request): JsonResponse
    {
        $filters = $this->filters($request) + ['limit' => 20000];
        unset($filters['from']);
        if ($request->query('asOf')) {
            $filters['to'] = $request->query('asOf');
        }

        try {
            $events = $this->eventAccess->rows($request, $filters);
        } catch (InvalidArgumentException $exception) {
            return $this->invalidPatientFilter($exception);
        }
        $state = $this->stateProjector->reconstruct($events, $request->query('asOf'));

        return $this->patientJson([
            'asOf' => $request->query('asOf'),
            'activePatients' => count($state),
            'patients' => array_values($state),
            'occupancy' => $this->stateProjector->occupancyByLocation($events, $request->query('asOf')),
        ]);
    }

    public function ambient(): JsonResponse
    {
        return response()->json($this->ambientSignals->summary($this->facilityCode()));
    }

    public function demoScenarios(): JsonResponse
    {
        return response()->json([
            'data' => $this->scenarios->all(),
            'meta' => [
                'enabled_keys' => $this->scenarios->enabledKeys(),
                'source_mode' => 'synthetic_demo',
                'generated_at' => now()->toJSON(),
            ],
        ]);
    }

    /**
     * Disk-ready occupancy detail contract for the 4D viewer.
     *
     * Unlike /events and /state, this endpoint is aggregate-safe: it computes
     * from patient-flow replay internally, then applies the active persona lens
     * to omit patient identity for roles whose `patient_dots` policy does not
     * allow it. It is the backend home for duration, origin, next move, timer,
     * delay, service-line compounding, and persona roll-up details.
     */
    public function occupancy(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $lens */
        $lens = $request->attributes->get('flow_lens');
        $roleId = (string) $request->attributes->get('flow_role_id');
        $asOf = is_string($request->query('asOf')) ? $request->query('asOf') : null;
        $time = $asOf ? CarbonImmutable::parse($asOf) : CarbonImmutable::now();
        $context = $this->eventAccess->context($request);

        return $this->patientJson($this->occupancyContext->build(
            $lens,
            $roleId,
            $time,
            $this->filters($request),
            $this->includes($request, 'eddy_context'),
            $context['scope'],
            $context['depth'],
            $context['task_refs'],
            $context['visible_unit_ids'],
        ));
    }

    public function occupancyHistory(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $lens */
        $lens = $request->attributes->get('flow_lens');
        $roleId = (string) $request->attributes->get('flow_role_id');
        $context = $this->eventAccess->context($request);

        try {
            return $this->patientJson($this->occupancyHistory->history(
                $lens,
                $roleId,
                $this->filters($request),
                $request->user(),
                $context['scope'],
                $context['depth'],
                $context['task_refs'],
                $context['visible_unit_ids'],
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->patientJson([
                'error' => [
                    'code' => 'invalid_occupancy_history_window',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Open operational barriers for the Navigator overlay. The flagship 48h spine
     * was barrier-blind — it read flow_core.flow_events only — so a bed/placement
     * hold never showed on the map next to the flow it was choking. This surfaces
     * the currently-open prod.barriers, each carrying its numeric unit_id so the
     * FE can anchor it to the unit's location centroid (buildProjectionPlacementIndex);
     * house-level barriers (unit_id null) render chronobar/HUD-only. ?unit_id filters.
     *
     * Aggregate + patient-free: identity linkage stays lens-redacted (encounter_ref
     * is deferred to the lens-aware follow-up), so it sits with the other aggregate
     * reads and is safe for every persona.
     */
    public function barriers(Request $request): JsonResponse
    {
        $open = Barrier::query()
            ->open()
            ->with('unit')
            ->when($request->filled('unit_id'), fn ($q) => $q->where('unit_id', $request->integer('unit_id')))
            ->orderBy('opened_at')
            ->get()
            ->map(fn (Barrier $b): array => [
                'barrier_id' => (int) $b->barrier_id,
                'unit_id' => $b->unit_id,
                'unit_label' => $b->unit?->name,
                'category' => (string) $b->category,
                'reason_code' => $b->reason_code,
                'description' => $b->description,
                'owner' => $b->owner,
                'status' => (string) $b->status,
                'opened_at' => optional($b->opened_at)->toJSON(),
                // Patient identity stays lens-redacted; population is a follow-up.
                'encounter_ref' => null,
            ])
            ->values();

        return response()->json([
            'generated_at' => now()->toJSON(),
            'count' => $open->count(),
            'open_barriers' => $open,
        ]);
    }

    /**
     * The +24h projection stream for the web Navigator's ghost layer —
     * FLOW-WINDOW-PLAN §6.4/§7.3. Same ForwardProjectionService and the same
     * persona lens as the mobile window (EnforceFlowLens attaches both), so
     * ghosts render identically in 3D and on the mobile plates.
     */
    public function projections(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $lens */
        $lens = $request->attributes->get('flow_lens');
        $roleId = (string) $request->attributes->get('flow_role_id');

        $now = \Carbon\CarbonImmutable::now();
        $to = $now->addHours(24);
        $context = $this->eventAccess->context($request);
        $scope = $context['scope'];
        $depth = $context['depth'];

        $items = app(\App\Services\Flow\ForwardProjectionService::class)
            ->projections($now, $to, $scope, $lens['projection_kinds']);

        $items = array_map(
            fn (array $item): array => $this->flowLens->redactRow(
                $item,
                $depth,
                $scope,
                $context['task_refs'],
                $context['visible_unit_ids'],
            ),
            $items,
        );

        return $this->patientJson([
            'window' => ['from' => $now->toIso8601String(), 'to' => $to->toIso8601String()],
            'lens' => ['role_id' => $roleId, 'projection_kinds' => $lens['projection_kinds'], 'patient_dots' => $depth],
            'scope' => [
                'type' => $scope['type'],
                'floor' => $scope['floor'],
                'unit_id' => $scope['unit_id'],
                'patient_context_ref' => $scope['patient_context_ref'],
                'label' => $scope['label'],
            ],
            'projections' => $items,
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function fhirBundle(Request $request): JsonResponse
    {
        $eventId = $request->query('event_id');
        if (! is_string($eventId) || $eventId === '') {
            return $this->patientJson(['error' => 'event_id is required'], 422);
        }

        $event = FlowEvent::query()
            ->with(['toFacilitySpace', 'fromFacilitySpace'])
            ->whereKey($eventId)
            ->first();

        if (! $event || ! ($payload = $this->eventAccess->event($request, $event))) {
            return $this->patientJson(['error' => 'event_id not found'], 404);
        }

        return $this->patientJson($this->fhir->makeFromPayload($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'patient' => $request->query('patient'),
            'category' => $request->query('category'),
            'service_line' => $request->query('service_line'),
            'floor' => $request->query('floor'),
            'demo' => $request->query('demo'),
            'scenario' => $request->query('scenario'),
            'limit' => $request->query('limit', 5000),
        ];
    }

    private function facilityCode(): string
    {
        return (string) config('facility_models.zep_500.facility_code', 'ZEPHYRUS-500');
    }

    /** @return array<string, mixed> */
    private function sourceEnvelope(?FlowEvent $latestEvent, CarbonImmutable $generatedAt): array
    {
        $expectedCadence = max(1, (int) config('patient_flow.expected_cadence_seconds', 300));
        $staleAfter = max($expectedCadence, (int) config('patient_flow.stale_after_seconds', 900));
        $lastEventAt = $latestEvent?->occurred_at?->toImmutable();
        $sourceKey = $latestEvent?->source?->source_key;
        $metadata = is_array($latestEvent?->metadata) ? $latestEvent->metadata : [];
        $sourceSystem = $sourceKey
            ?: (is_string($metadata['source_system'] ?? null) ? $metadata['source_system'] : null)
            ?: $latestEvent?->source_protocol
            ?: 'patient-flow';
        $sourceName = strtolower((string) $sourceSystem);
        $dataOrigin = strtolower((string) ($metadata['data_origin'] ?? ''));
        $synthetic = $dataOrigin === 'synthetic'
            || str_contains($sourceName, 'synthetic')
            || str_contains($sourceName, 'demo');
        $seeded = str_contains($sourceName, 'seed');

        $freshness = 'missing';
        if ($lastEventAt) {
            $ageSeconds = max(0, $lastEventAt->diffInSeconds($generatedAt, false));
            $freshness = $ageSeconds > $staleAfter ? 'stale' : 'fresh';
        }

        return [
            'mode' => $synthetic ? 'synthetic' : ($seeded ? 'seeded' : 'live'),
            'system' => $sourceSystem,
            'scenario_id' => null,
            'generated_at' => $generatedAt->toJSON(),
            'last_event_at' => $lastEventAt?->toJSON(),
            'expected_cadence_seconds' => $expectedCadence,
            'freshness' => $freshness,
            'stale_after_seconds' => $staleAfter,
            'lineage' => array_values(array_filter([
                'flow_core.flow_events',
                $sourceKey ? 'integration.sources:'.$sourceKey : null,
            ])),
        ];
    }

    private function includes(Request $request, string $feature): bool
    {
        return in_array($feature, array_filter(array_map(
            fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) $request->query('include', '')),
        )), true);
    }

    /** @param array<string, mixed>|list<mixed> $payload */
    private function patientJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function invalidPatientFilter(InvalidArgumentException $exception): JsonResponse
    {
        return $this->patientJson([
            'error' => [
                'code' => 'invalid_patient_context_ref',
                'message' => $exception->getMessage(),
            ],
        ], 422);
    }

    /** @param array<string,array<string,mixed>> $locations */
    private function attachOpsGraphNodes(array $locations): array
    {
        if (! Schema::hasTable('ops.nodes') || $locations === []) {
            return $locations;
        }

        $keyByLocation = [];
        foreach ($locations as $locationCode => $location) {
            $keys = [
                "facility_space:{$location['facility_space_id']}",
                "location:{$locationCode}",
            ];

            if (! empty($location['unit_code'])) {
                $keys[] = "unit:{$location['unit_code']}";
            }

            foreach ($keys as $key) {
                $keyByLocation[$key][] = $locationCode;
            }
        }

        $nodes = DB::table('ops.nodes')
            ->whereIn('canonical_key', array_keys($keyByLocation))
            ->where('is_active', true)
            ->get();

        foreach ($locations as $locationCode => $location) {
            $locations[$locationCode]['ops_graph_nodes'] = [];
        }

        foreach ($nodes as $node) {
            foreach ($keyByLocation[$node->canonical_key] ?? [] as $locationCode) {
                $locations[$locationCode]['ops_graph_nodes'][] = [
                    'graphNodeId' => (int) $node->graph_node_id,
                    'nodeUuid' => $node->node_uuid,
                    'nodeType' => $node->node_type,
                    'canonicalKey' => $node->canonical_key,
                    'displayName' => $node->display_name,
                    'status' => $node->status,
                    'currentState' => json_decode($node->current_state ?? '{}', true) ?: [],
                ];
            }
        }

        return $locations;
    }
}
