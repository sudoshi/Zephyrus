<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cockpit\CockpitSnapshot;
use App\Support\Hospital\HospitalManifest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/cockpit/stream — the SSE fallback for wall displays that can't
 * hold a Reverb WebSocket (plan P1 workstream 4; modeled on
 * PatientFlowStreamController). Emits a PHI-free reload ping
 * ({facilityKey, generatedAtIso}) whenever the snapshot row advances —
 * the client then refetches /api/cockpit/snapshot (ETag-cheap). Bounded
 * duration: the loop ends after `cycles` polls and EventSource reconnects,
 * so Apache/FPM workers are never held indefinitely.
 */
class CockpitStreamController extends Controller
{
    public function __construct(private readonly HospitalManifest $manifest) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $cycles = min(max((int) $request->query('cycles', 25), 1), 60);
        $interval = min(max((float) $request->query('interval', 2.0), 0.05), 10.0);
        $facilityKey = $this->manifest->facilityCode();

        return response()->stream(function () use ($cycles, $interval, $facilityKey): void {
            echo ": connected\n\n";
            flush();

            $lastSeen = null;

            for ($i = 0; $i < $cycles; $i++) {
                if (connection_aborted()) {
                    break;
                }

                $generatedAt = CockpitSnapshot::query()
                    ->whereKey($facilityKey)
                    ->first(['generated_at'])
                    ?->generated_at
                    ?->toIso8601String();

                if ($generatedAt !== null && $generatedAt !== $lastSeen) {
                    $lastSeen = $generatedAt;
                    echo 'id: '.sha1($facilityKey.'|'.$generatedAt)."\n";
                    echo "event: cockpit-snapshot\n";
                    echo 'data: '.json_encode([
                        'facilityKey' => $facilityKey,
                        'generatedAtIso' => $generatedAt,
                    ], JSON_UNESCAPED_SLASHES)."\n\n";
                } else {
                    echo ": heartbeat\n\n";
                }

                flush();

                if ($i < $cycles - 1) {
                    usleep((int) ($interval * 1_000_000));
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
