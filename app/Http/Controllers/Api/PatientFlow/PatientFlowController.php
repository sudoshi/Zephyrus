<?php

namespace App\Http\Controllers\Api\PatientFlow;

use App\Http\Controllers\Controller;
use App\Models\PatientFlow\FlowEvent;
use App\Services\PatientFlow\FacilitySpaceLocationResolver;
use App\Services\PatientFlow\FhirBundleFactory;
use App\Services\PatientFlow\FlowEventRepository;
use App\Services\PatientFlow\PatientStateProjector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientFlowController extends Controller
{
    public function __construct(
        private readonly FlowEventRepository $events,
        private readonly FacilitySpaceLocationResolver $locations,
        private readonly PatientStateProjector $stateProjector,
        private readonly FhirBundleFactory $fhir,
    ) {}

    public function summary(): JsonResponse
    {
        $eventCount = (int) DB::table('flow_core.flow_events')->count();

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
            'facility_code' => $this->facilityCode(),
            'model_url' => (string) config('facility_models.zep_500.model_url'),
            'tileset_url' => (string) config('facility_models.zep_500.tileset_url'),
            'generated_at' => now()->toJSON(),
        ]);
    }

    public function locations(): JsonResponse
    {
        return response()->json($this->locations->allNavigatorLocations($this->facilityCode()));
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
            'limit' => $request->query('limit', 5000),
        ];
    }

    private function facilityCode(): string
    {
        return (string) config('facility_models.zep_500.facility_code', 'ZEPHYRUS-500');
    }
}
