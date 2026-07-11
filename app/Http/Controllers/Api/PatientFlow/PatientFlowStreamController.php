<?php

namespace App\Http\Controllers\Api\PatientFlow;

use App\Http\Controllers\Controller;
use App\Services\PatientFlow\PatientFlowEventAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientFlowStreamController extends Controller
{
    public function __construct(private readonly PatientFlowEventAccessService $eventAccess) {}

    public function __invoke(Request $request): StreamedResponse|JsonResponse
    {
        $replay = min(max((int) $request->query('replay', 160), 1), 500);
        $interval = max((float) $request->query('interval', 1.0), 0.05);
        try {
            $events = $this->eventAccess->rows($request, [
                'limit' => $replay,
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'patient' => $request->query('patient'),
                'category' => $request->query('category'),
                'service_line' => $request->query('service_line'),
                'floor' => $request->query('floor'),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_patient_context_ref',
                    'message' => $exception->getMessage(),
                ],
            ], 422)->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
            ]);
        }

        return response()->stream(function () use ($events, $interval) {
            echo ": connected\n\n";
            flush();

            foreach ($events as $index => $event) {
                if (connection_aborted()) {
                    break;
                }

                $payload = $event + [
                    'stream_sequence' => $index + 1,
                    'streamed_at_epoch_ms' => (int) floor(microtime(true) * 1000),
                ];

                echo 'id: '.$payload['event_id']."\n";
                echo "event: patient-flow\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
                flush();
                usleep((int) ($interval * 1_000_000));
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
