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
        $reverb = config('broadcasting.connections.reverb', []);
        $options = $reverb['options'] ?? [];

        $channels = config('hummingbird.realtime_channels', []);
        foreach ($request->user()->units()->get()->pluck('unit_id') as $unitId) {
            $channels[] = 'unit.'.$unitId;
        }

        return $this->envelope([
            'scheme' => 'reverb-pusher',
            'key' => $reverb['key'] ?? null,
            'host' => $options['host'] ?? null,
            'port' => (int) ($options['port'] ?? 443),
            'tls' => ($options['scheme'] ?? 'https') === 'https',
            'channels' => array_values(array_unique($channels)),
            'note' => 'Reverb does not replay; re-snapshot tracked queries on every (re)connect.',
        ]);
    }
}
