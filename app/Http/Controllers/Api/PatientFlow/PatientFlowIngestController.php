<?php

namespace App\Http\Controllers\Api\PatientFlow;

use App\Exceptions\PatientFlowIngestException;
use App\Http\Controllers\Controller;
use App\Services\PatientFlow\PatientFlowHl7IngestPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientFlowIngestController extends Controller
{
    public function __construct(
        private readonly PatientFlowHl7IngestPipeline $pipeline,
    ) {}

    public function hl7v2(Request $request): JsonResponse
    {
        $body = $request->isJson()
            ? (string) $request->input('raw_hl7', '')
            : (string) $request->getContent();

        if (trim($body) === '') {
            return $this->error('raw_hl7_required', 'raw_hl7 body is required.', 400);
        }

        $maxBytes = max(1024, (int) config('patient_flow.hl7_ingest_max_bytes', 1_048_576));
        if (strlen($body) > $maxBytes) {
            return $this->error(
                'hl7_payload_too_large',
                "The HL7 payload exceeds the {$maxBytes}-byte limit.",
                413,
            );
        }

        $sourceKey = $request->header('X-Integration-Source');
        if (! is_string($sourceKey) || trim($sourceKey) === '') {
            return $this->error(
                'integration_source_required',
                'X-Integration-Source is required.',
                422,
            );
        }

        try {
            $receipt = $this->pipeline->ingest(
                trim($sourceKey),
                $body,
                $request->header('Idempotency-Key'),
                [
                    'request_id' => $request->header('X-Request-ID'),
                    'machine_token_id' => $request->user()?->currentAccessToken()?->getKey(),
                    'authenticated_user_id' => $request->user()?->getAuthIdentifier(),
                ],
            );
        } catch (PatientFlowIngestException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return response()->json($receipt, $receipt['duplicate'] ? 200 : 202)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
