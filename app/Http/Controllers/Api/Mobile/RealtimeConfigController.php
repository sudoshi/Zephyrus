<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/mobile/v1/realtime/config
 *
 * Returns the Reverb (Pusher-protocol) connection details + the PHI-free public
 * channels this user should subscribe to (house roll-up + their unit channels).
 * Reverb does not replay, so clients must re-snapshot tracked queries on every
 * (re)connect.
 */
class RealtimeConfigController extends Controller
{
    use RendersMobileEnvelope;

    public function show(Request $request): JsonResponse
    {
        // The app KEY comes from the broadcaster credentials, but the host/port/scheme
        // advertised here are the PUBLIC endpoint Apache fronts — NOT the broadcaster's
        // loopback trigger target (broadcasting.connections.reverb.options.*), which in
        // production points at 127.0.0.1 to keep publishes off the TLS edge.
        $key = config('broadcasting.connections.reverb.key');
        $public = config('hummingbird.realtime_public', []);

        $channels = config('hummingbird.realtime_channels', []);
        foreach ($request->user()->units()->get()->pluck('unit_id') as $unitId) {
            $channels[] = 'unit.'.$unitId;
        }

        return $this->envelope([
            'scheme' => 'reverb-pusher',
            'key' => $key,
            'host' => $public['host'] ?? null,
            'port' => (int) ($public['port'] ?? 443),
            'tls' => ($public['scheme'] ?? 'https') === 'https',
            'channels' => array_values(array_unique($channels)),
            'note' => 'Reverb does not replay; re-snapshot tracked queries on every (re)connect.',
        ]);
    }
}
