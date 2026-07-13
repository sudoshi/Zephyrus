<?php

namespace App\Services\Push;

use App\Contracts\PushNotifier;
use App\Models\User;
use App\Security\ClinicalPayloads\ClinicalContentGuard;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real APNs sender (token-based .p8 auth, HTTP/2). PHI-free by contract: title/body are
 * generic per the earned-urgency taxonomy and the data payload carries ids/deep-links only.
 * Bound in place of {@see LogPushNotifier} when APNs is configured (see HummingbirdServiceProvider).
 * iOS-only here; Android/FCM is a sibling sender for later.
 */
class ApnsPushNotifier implements PushNotifier
{
    private readonly ClinicalContentGuard $clinicalContent;

    /**
     * @param  array<string, mixed>  $config  config('hummingbird.apns')
     */
    public function __construct(private readonly array $config, ?ClinicalContentGuard $clinicalContent = null)
    {
        $this->clinicalContent = $clinicalContent ?? app(ClinicalContentGuard::class);
    }

    /**
     * @param  array<string, mixed>  $c
     */
    public static function isConfigured(array $c): bool
    {
        return ! empty($c['key_id']) && ! empty($c['team_id']) && ! empty($c['bundle_id'])
            && (! empty($c['private_key']) || ! empty($c['private_key_path']));
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $this->clinicalContent->assertSafe(
            ['title' => $title, 'notification_text' => $body, 'data' => $data],
            'clinical_content_alert_rejected',
        );
        $devices = $user->mobileDevices()->whereNull('revoked_at')->where('platform', 'ios')->get();
        if ($devices->isEmpty()) {
            return 0;
        }

        $jwt = $this->authToken();
        $host = ($this->config['production'] ?? false)
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        // PHI-free payload: generic alert + a small data bag (e.g. a deep-link tab).
        $payload = ['aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default']] + $data;

        $sent = 0;
        foreach ($devices as $device) {
            $resp = Http::withHeaders([
                'authorization' => 'bearer '.$jwt,
                'apns-topic' => $this->config['bundle_id'],
                'apns-push-type' => 'alert',
            ])->withOptions(['version' => 2.0])
                ->post("{$host}/3/device/{$device->push_token}", $payload);

            if ($resp->successful()) {
                $sent++;
                $device->forceFill(['last_seen_at' => now()])->save();

                continue;
            }

            // 410 Gone or BadDeviceToken → the token is dead; stop sending to it.
            if ($resp->status() === 410 || $resp->json('reason') === 'BadDeviceToken' || $resp->json('reason') === 'Unregistered') {
                $device->forceFill(['revoked_at' => now()])->save();

                continue;
            }

            Log::warning('hummingbird.push.apns_failed', [
                'device_id' => $device->mobile_device_id,
                'status' => $resp->status(),
                'reason' => $resp->json('reason'),
            ]);
        }

        return $sent;
    }

    /**
     * The APNs provider JWT (ES256), cached just under the 1-hour APNs limit.
     */
    private function authToken(): string
    {
        return Cache::remember('hummingbird.apns.jwt', now()->addMinutes(50), function () {
            return JWT::encode(
                ['iss' => $this->config['team_id'], 'iat' => time()],
                $this->privateKeyPem(),
                'ES256',
                $this->config['key_id'],
            );
        });
    }

    private function privateKeyPem(): string
    {
        if (! empty($this->config['private_key'])) {
            return (string) $this->config['private_key'];
        }

        return (string) file_get_contents($this->config['private_key_path']);
    }
}
