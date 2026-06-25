<?php

namespace App\Http\Controllers\Api\PatientFlow;

use App\Http\Controllers\Controller;
use App\Services\PatientFlow\FlowEventNormalizer;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientFlowIngestController extends Controller
{
    public function __construct(
        private readonly FlowEventNormalizer $normalizer,
        private readonly FlowEventRepository $events,
    ) {}

    public function hl7v2(Request $request): JsonResponse
    {
        $body = $request->isJson()
            ? (string) $request->input('raw_hl7', '')
            : (string) $request->getContent();

        if (trim($body) === '') {
            return response()->json(['error' => 'raw_hl7 body is required'], 400);
        }

        $event = $this->normalizer->normalize($body, 'hl7v2-post');
        $stored = $this->events->upsertNormalizedEvent(
            $event,
            null,
            null,
            null,
            (string) config('facility_models.zep_500.facility_code', 'ZEPHYRUS-500'),
        );

        return response()->json([
            'accepted' => true,
            'event' => $this->events->serializeEvent($stored->load(['toFacilitySpace', 'fromFacilitySpace'])),
        ], 202);
    }
}
