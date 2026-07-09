<?php

namespace App\Http\Controllers\Api\PatientFlow;

use App\Http\Controllers\Controller;
use App\Models\PatientFlow\FlowEvent;
use App\Services\PatientFlow\AmbientSignalService;
use App\Services\PatientFlow\FacilitySpaceLocationResolver;
use App\Services\PatientFlow\FhirBundleFactory;
use App\Services\PatientFlow\FlowEventRepository;
use App\Services\PatientFlow\PatientFlowOccupancyContextService;
use App\Services\PatientFlow\PatientFlowOccupancyHistoryService;
use App\Services\PatientFlow\PatientFlowScenarioRegistry;
use App\Services\PatientFlow\PatientStateProjector;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PatientFlowController extends Controller
{
    public function __construct(
        private readonly FlowEventRepository $events,
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
            'min_occurred_at' => DB::table('flow_core.flow_events')->min('occurred_at'),
            'max_occurred_at' => DB::table('flow_core.flow_events')->max('occurred_at'),
            'live_events' => 0,
            'ambient_signals' => $ambient['summary']['eventCount'],
            'ambient_confidence' => $ambient['summary']['averageConfidence'],
            'ambient_confidence_level' => $ambient['summary']['confidenceLevel'],
            'facility_code' => $this->facilityCode(),
            'model_url' => (string) config('facility_models.zep_500.model_url'),
            'tileset_url' => (string) config('facility_models.zep_500.tileset_url'),
            'generated_at' => now()->toJSON(),
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
        return response()->json($this->events->serializeEvents(
            $this->events->filteredEvents($this->filters($request)),
        ));
    }

    public function tracks(Request $request): JsonResponse
    {
        $tracks = [];
        foreach ($this->events->serializeEvents($this->events->filteredEvents($this->filters($request))) as $event) {
            $tracks[$event['patient_id']][] = $event;
        }

        return response()->json($tracks);
    }

    public function state(Request $request): JsonResponse
    {
        $filters = $this->filters($request) + ['limit' => 20000];
        unset($filters['from']);
        if ($request->query('asOf')) {
            $filters['to'] = $request->query('asOf');
        }

        $events = $this->events->serializeEvents($this->events->filteredEvents($filters));
        $state = $this->stateProjector->reconstruct($events, $request->query('asOf'));

        return response()->json([
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

        return response()->json($this->occupancyContext->build(
            $lens,
            $roleId,
            $time,
            $this->filters($request),
            $this->includes($request, 'eddy_context'),
        ));
    }

    public function occupancyHistory(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $lens */
        $lens = $request->attributes->get('flow_lens');
        $roleId = (string) $request->attributes->get('flow_role_id');

        return response()->json($this->occupancyHistory->history($lens, $roleId, $this->filters($request)));
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
        $scope = ['type' => 'house', 'floor' => null, 'unit_id' => null, 'patient_ref' => null];

        $items = app(\App\Services\Flow\ForwardProjectionService::class)
            ->projections($now, $to, $scope, $lens['projection_kinds']);

        // Same redaction implementation as the mobile window. At house scope
        // only `full` keeps identity — unit/task depths collapse to none here
        // (the web ghost layer is aggregate for those roles).
        $depth = $lens['patient_dots'] === 'full' ? 'full' : 'none';
        $lensService = app(\App\Services\Flow\FlowLensService::class);
        $items = array_map(
            fn (array $item): array => $lensService->redactRow($item, $depth, $scope),
            $items,
        );

        return response()->json([
            'window' => ['from' => $now->toIso8601String(), 'to' => $to->toIso8601String()],
            'lens' => ['role_id' => $roleId, 'projection_kinds' => $lens['projection_kinds'], 'patient_dots' => $lens['patient_dots']],
            'projections' => $items,
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function fhirBundle(Request $request): JsonResponse
    {
        $eventId = $request->query('event_id');
        if (! is_string($eventId) || $eventId === '') {
            return response()->json(['error' => 'event_id is required'], 422);
        }

        $event = FlowEvent::query()
            ->with(['toFacilitySpace', 'fromFacilitySpace'])
            ->whereKey($eventId)
            ->first();

        if (! $event) {
            return response()->json(['error' => 'event_id not found'], 404);
        }

        return response()->json($this->fhir->make($event));
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

    private function includes(Request $request, string $feature): bool
    {
        return in_array($feature, array_filter(array_map(
            fn (string $value): string => strtolower(trim($value)),
            explode(',', (string) $request->query('include', '')),
        )), true);
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
