<?php

namespace App\Services\Auth\Oidc;

use App\Models\Auth\AuthProviderSetting;

class OidcProviderConfig
{
    public function settings(): array
    {
        $provider = $this->provider();
        $stored = $provider?->settings ?? [];

        return [
            'enabled' => $this->enabled($provider),
            'display_name' => $provider?->display_name ?? 'Sign in with Authentik',
            'discovery_url' => $this->stringSetting($stored, 'discovery_url', (string) config('services.oidc.discovery_url', '')),
            'client_id' => $this->stringSetting($stored, 'client_id', (string) config('services.oidc.client_id', '')),
            'client_secret' => (string) config('services.oidc.client_secret', ''), // secret ONLY from env
            'redirect_uri' => $this->stringSetting($stored, 'redirect_uri', (string) config('services.oidc.redirect_uri', '')),
            'scopes' => $this->listSetting($stored, 'scopes', (array) config('services.oidc.scopes', ['openid', 'profile', 'email'])),
            'allowed_groups' => $this->listSetting($stored, 'allowed_groups', (array) config('services.oidc.allowed_groups', ['Zephyrus Users'])),
            'admin_groups' => $this->listSetting($stored, 'admin_groups', (array) config('services.oidc.admin_groups', ['Zephyrus Admins'])),
        ];
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'];
    }

    public function isPubliclyAvailable(): bool
    {
        $s = $this->settings();

        return $s['enabled'] && $s['discovery_url'] !== '' && $s['client_id'] !== '' && $s['redirect_uri'] !== '';
    }

    public function displayName(): string
    {
        return $this->settings()['display_name'];
    }

    public function discoveryUrl(): string
    {
        return $this->settings()['discovery_url'];
    }

    public function clientId(): string
    {
        return $this->settings()['client_id'];
    }

    public function clientSecret(): string
    {
        return $this->settings()['client_secret'];
    }

    public function redirectUri(): string
    {
        return $this->settings()['redirect_uri'];
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->settings()['scopes'];
    }

    /** @return list<string> */
    public function allowedGroups(): array
    {
        return $this->settings()['allowed_groups'];
    }

    /** @return list<string> */
    public function adminGroups(): array
    {
        return $this->settings()['admin_groups'];
    }

    private function provider(): ?AuthProviderSetting
    {
        try {
            return AuthProviderSetting::query()->where('provider_type', 'oidc')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function enabled(?AuthProviderSetting $provider): bool
    {
        return (bool) config('services.oidc.enabled', false) || (bool) ($provider?->is_enabled ?? false);
    }

    private function stringSetting(array $settings, string $key, string $fallback): string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    /** @return list<string> */
    private function listSetting(array $settings, string $key, array $fallback): array
    {
        $value = $settings[$key] ?? null;
        $items = is_array($value) && $value !== [] ? $value : $fallback;

        return array_values(array_filter(array_map(
            static fn (mixed $i): string => is_string($i) ? trim($i) : '',
            $items,
        )));
    }
}
