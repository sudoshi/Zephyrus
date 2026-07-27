<?php

namespace App\Services\Patient;

use App\Models\Patient\PatientNotificationDevice;
use App\Models\Patient\PatientPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns the patient-realm registry of encrypted provider-token bindings.
 *
 * Registration is deliberately separate from authentication sessions and
 * provider delivery. A token can only ever yield generic patient-safe API
 * errors, and no method here constructs a notification payload.
 */
final class PatientNotificationDeviceRegistry
{
    public function __construct(
        private readonly PatientNotificationDeviceCipher $cipher,
        private readonly PatientHmac $hmac,
        private readonly PatientAccessAuditRecorder $audit,
    ) {}

    /**
     * @param  array{platform:string,environment:string,installation_uuid:string,push_token:string,app_version?:string|null,os_version?:string|null,locale?:string|null}  $input
     */
    public function register(
        Request $request,
        PatientPrincipal $principal,
        string $deviceUuid,
        array $input,
    ): PatientNotificationDevice {
        $deviceUuid = $this->canonicalUuid($deviceUuid);
        $keyVersion = trim((string) config(
            'hummingbird-patient.notification_devices.encryption_key_version',
        ));

        try {
            $this->hmac->assertAvailable();
            if ($keyVersion === '') {
                throw new RuntimeException('patient_notification_device_encryption_key_version_unavailable');
            }
            $this->cipher->assertAvailable($keyVersion);
        } catch (RuntimeException) {
            throw PatientNotificationDeviceFailure::unavailable();
        }

        $token = $input['push_token'];
        $tokenDigest = $this->hmac->digest('notification-device-token', $token);

        return DB::transaction(function () use (
            $request,
            $principal,
            $deviceUuid,
            $keyVersion,
            $input,
            $token,
            $tokenDigest,
        ): PatientNotificationDevice {
            $this->lockRegistration($deviceUuid, $tokenDigest);

            $sameDevice = PatientNotificationDevice::query()
                ->where('device_uuid', $deviceUuid)
                ->lockForUpdate()
                ->first();
            if ($sameDevice !== null && $sameDevice->principal_id !== $principal->getKey()) {
                $this->audit->bestEffort(
                    $request,
                    'patient.notification_device.registration_denied',
                    'security',
                    'register_notification_device',
                    'denied',
                    $principal,
                    reasonCode: 'not_found',
                    resourceType: 'patient_notification_device',
                    resourceUuid: $deviceUuid,
                );
                throw PatientNotificationDeviceFailure::notFound();
            }

            $sameToken = PatientNotificationDevice::query()
                ->active()
                ->where('push_token_digest', $tokenDigest)
                ->lockForUpdate()
                ->first();
            $tokenRebound = $sameToken !== null && $sameToken->device_uuid !== $deviceUuid;
            if ($sameToken !== null && $sameToken->device_uuid !== $deviceUuid) {
                $this->markRevoked($sameToken, 'token_rebound');
            }

            $sameInstallation = PatientNotificationDevice::query()
                ->active()
                ->where('principal_id', $principal->getKey())
                ->where('platform', $input['platform'])
                ->where('environment', $input['environment'])
                ->where('installation_uuid', $input['installation_uuid'])
                ->lockForUpdate()
                ->first();
            if ($sameInstallation !== null && $sameInstallation->device_uuid !== $deviceUuid) {
                $this->markRevoked($sameInstallation, 'installation_replaced');
            }

            $attributes = [
                'principal_id' => $principal->getKey(),
                'platform' => $input['platform'],
                'environment' => $input['environment'],
                'installation_uuid' => $input['installation_uuid'],
                'encrypted_push_token' => $this->cipher->encrypt(
                    $token,
                    $keyVersion,
                    $this->cipher->contextFor($deviceUuid),
                ),
                'encryption_key_version' => $keyVersion,
                'push_token_digest' => $tokenDigest,
                'app_version' => $input['app_version'] ?? null,
                'os_version' => $input['os_version'] ?? null,
                'locale' => $input['locale'] ?? null,
                'status' => 'active',
                'last_seen_at' => now(),
                'revoked_at' => null,
                'revocation_reason' => null,
            ];

            $wasExisting = $sameDevice !== null;
            $device = $sameDevice ?? new PatientNotificationDevice(['device_uuid' => $deviceUuid]);
            $device->fill($attributes);
            $device->save();

            $this->audit->record(
                $request,
                'patient.notification_device.registered',
                'security',
                'register_notification_device',
                'succeeded',
                $principal,
                resourceType: 'patient_notification_device',
                resourceUuid: $deviceUuid,
                metadata: [
                    'platform' => $device->platform,
                    'environment' => $device->environment,
                    'existing_registration_updated' => $wasExisting,
                    'previous_registration_rebound' => $tokenRebound,
                ],
            );

            return $device->refresh();
        }, 3);
    }

    /** @return array{device:PatientNotificationDevice,already_revoked:bool} */
    public function revoke(Request $request, PatientPrincipal $principal, string $deviceUuid): array
    {
        $deviceUuid = $this->canonicalUuid($deviceUuid);

        return DB::transaction(function () use ($request, $principal, $deviceUuid): array {
            $device = PatientNotificationDevice::query()
                ->where('principal_id', $principal->getKey())
                ->where('device_uuid', $deviceUuid)
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                $this->audit->bestEffort(
                    $request,
                    'patient.notification_device.revoke_denied',
                    'security',
                    'revoke_notification_device',
                    'denied',
                    $principal,
                    reasonCode: 'not_found',
                    resourceType: 'patient_notification_device',
                    resourceUuid: $deviceUuid,
                );
                throw PatientNotificationDeviceFailure::notFound();
            }

            $alreadyRevoked = $device->status !== 'active';
            if (! $alreadyRevoked) {
                $this->markRevoked($device, 'patient_revoked');
                $this->audit->record(
                    $request,
                    'patient.notification_device.revoked',
                    'security',
                    'revoke_notification_device',
                    'succeeded',
                    $principal,
                    resourceType: 'patient_notification_device',
                    resourceUuid: $deviceUuid,
                    metadata: ['already_revoked' => false],
                );
            }

            return ['device' => $device->refresh(), 'already_revoked' => $alreadyRevoked];
        }, 3);
    }

    private function markRevoked(PatientNotificationDevice $device, string $reason): void
    {
        $device->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ])->save();
    }

    private function canonicalUuid(string $uuid): string
    {
        if (! Str::isUuid($uuid)) {
            throw PatientNotificationDeviceFailure::notFound();
        }

        return Str::lower($uuid);
    }

    private function lockRegistration(string $deviceUuid, string $tokenDigest): void
    {
        $keys = [
            'patient-notification-device:'.$deviceUuid,
            'patient-notification-token:'.$tokenDigest,
        ];
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }
}
