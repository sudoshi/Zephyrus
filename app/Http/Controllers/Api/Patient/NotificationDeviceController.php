<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Concerns\RendersPatientEnvelope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\RegisterPatientNotificationDeviceRequest;
use App\Models\Patient\PatientNotificationDevice;
use App\Models\Patient\PatientPrincipal;
use App\Services\Patient\PatientNotificationDeviceFailure;
use App\Services\Patient\PatientNotificationDeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationDeviceController extends Controller
{
    use RendersPatientEnvelope;

    public function __construct(private readonly PatientNotificationDeviceRegistry $devices) {}

    public function store(
        RegisterPatientNotificationDeviceRequest $request,
        string $deviceUuid,
    ): JsonResponse {
        return $this->attempt(function () use ($request, $deviceUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $device = $this->devices->register($request, $principal, $deviceUuid, $request->validated());

            return $this->patientEnvelope(['device' => $this->serialize($device)]);
        });
    }

    public function destroy(Request $request, string $deviceUuid): JsonResponse
    {
        return $this->attempt(function () use ($request, $deviceUuid): JsonResponse {
            /** @var PatientPrincipal $principal */
            $principal = $request->user();
            $result = $this->devices->revoke($request, $principal, $deviceUuid);

            return $this->patientEnvelope([
                'device_uuid' => (string) $result['device']->device_uuid,
                'revoked' => true,
                'already_revoked' => $result['already_revoked'],
            ]);
        });
    }

    private function attempt(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (PatientNotificationDeviceFailure $failure) {
            return response()->json([
                'error' => ['code' => $failure->errorCode],
            ], $failure->httpStatus);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(PatientNotificationDevice $device): array
    {
        return [
            'device_uuid' => (string) $device->device_uuid,
            'platform' => (string) $device->platform,
            'environment' => (string) $device->environment,
            'status' => (string) $device->status,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
        ];
    }
}
