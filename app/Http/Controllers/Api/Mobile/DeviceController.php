<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Concerns\RendersMobileEnvelope;
use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST   /api/mobile/v1/devices         — register/refresh an APNs/FCM push token.
 * DELETE /api/mobile/v1/devices/{device} — revoke a device (logout / lost device).
 */
class DeviceController extends Controller
{
    use RendersMobileEnvelope;

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:ios,android'],
            'push_token' => ['required', 'string', 'max:512'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();

        // Upsert by push token: a re-registered token re-binds to this user and
        // is un-revoked. device_uuid is assigned by the model on first create.
        $device = MobileDevice::updateOrCreate(
            ['push_token' => $validated['push_token']],
            [
                'user_id' => $user->getKey(),
                'platform' => $validated['platform'],
                'app_version' => $validated['app_version'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'locale' => $validated['locale'] ?? null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        return $this->envelope([
            'device_uuid' => $device->device_uuid,
            'platform' => $device->platform,
        ], status: 201);
    }

    public function destroy(Request $request, string $device): JsonResponse
    {
        $record = MobileDevice::query()
            ->where('device_uuid', $device)
            ->where('user_id', $request->user()->getKey())
            ->first();

        if (! $record) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Device not found.'],
            ], 404);
        }

        $record->update(['revoked_at' => now()]);

        return $this->envelope(['revoked' => true]);
    }
}
