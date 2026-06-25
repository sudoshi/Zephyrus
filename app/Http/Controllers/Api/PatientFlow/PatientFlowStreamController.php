<?php

namespace App\Http\Controllers\Api\PatientFlow;

use App\Http\Controllers\Controller;
use App\Services\PatientFlow\FlowEventRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientFlowStreamController extends Controller
{
    public function __construct(private readonly FlowEventRepository $events) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $replay = min(max((int) $request->query('replay', 160), 1), 500);
        $interval = max((float) $request->query('interval', 1.0), 0.05);
        $events = $this->events->serializeEvents($this->events->filteredEvents([
            'limit' => $replay,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'patient' => $request->query('patient'),
            'category' => $request->query('category'),
            'service_line' => $request->query('service_line'),
            'floor' => $request->query('floor'),
        ]));

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
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
