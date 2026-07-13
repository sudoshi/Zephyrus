<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Auth\AuthProviderSetting;
use App\Security\Network\OidcUrlPolicy;
use App\Security\Network\UnsafeOidcUrl;
use App\Services\Audit\UserAuditRecorder;
use App\Services\Auth\Oidc\Exceptions\OidcException;
use App\Services\Auth\Oidc\OidcDiscoveryService;
use App\Services\Auth\Oidc\OidcProviderConfig;
use App\Services\Auth\StepUpAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthProviderController extends Controller
{
    private const EDITABLE_SETTING_KEYS = [
        'discovery_url',
        'client_id',
        'redirect_uri',
        'scopes',
        'allowed_groups',
        'admin_groups',
    ];

    public function __construct(
        private readonly UserAuditRecorder $audit,
        private readonly OidcProviderConfig $oidc,
        private readonly OidcUrlPolicy $urlPolicy,
        private readonly StepUpAuthenticationService $stepUp,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewIdentity');

        $row = AuthProviderSetting::query()->where('provider_type', 'oidc')->first();
        $effective = $this->oidc->settings();

        return Inertia::render('Admin/AuthProviders', [
            'local' => [
                'enabled' => (bool) config('auth-drivers.local.enabled', true),
                'registrationEnabled' => (bool) config('auth-drivers.local.registration_enabled', false),
            ],
            'oidc' => [
                'providerType' => 'oidc',
                'stored' => [
                    'exists' => $row !== null,
                    'enabled' => (bool) ($row?->is_enabled ?? false),
                    'displayName' => $row?->display_name ?? 'Sign in with Authentik',
                    'settings' => $this->publicSettings($row?->settings ?? []),
                ],
                'effective' => [
                    'enabled' => (bool) $effective['enabled'],
                    'publiclyAvailable' => $this->oidc->isPubliclyAvailable(),
                    'displayName' => (string) $effective['display_name'],
                    'settings' => $this->publicSettings($effective),
                    'clientSecretConfigured' => (string) $effective['client_secret'] !== '',
                ],
                'networkPolicy' => [
                    'allowedHosts' => $this->urlPolicy->allowedHosts(),
                    'allowedRedirectUris' => array_values((array) config('auth-drivers.oidc_network.allowed_redirect_uris', [])),
                    'privateNetworksAllowed' => (bool) config('auth-drivers.oidc_network.allow_private_networks', false),
                ],
            ],
        ]);
    }

    public function show(string $type): JsonResponse
    {
        Gate::authorize('viewIdentity');
        abort_unless($type === 'oidc', 404);

        $row = AuthProviderSetting::query()->where('provider_type', $type)->first();

        return response()->json([
            'provider_type' => $type,
            'is_enabled' => (bool) ($row?->is_enabled ?? false),
            'display_name' => $row?->display_name,
            'settings' => $this->publicSettings($row?->settings ?? []),
        ]);
    }

    public function update(Request $request, string $type): JsonResponse
    {
        Gate::authorize('manageIdentity');
        abort_unless($type === 'oidc', 404);

        $validated = $request->validate([
            'is_enabled' => 'sometimes|boolean',
            'display_name' => 'sometimes|string|max:80',
            'settings' => ['sometimes', 'array:'.implode(',', self::EDITABLE_SETTING_KEYS)],
            'settings.discovery_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048', $this->outboundUrlRule()],
            'settings.client_id' => 'sometimes|nullable|string|max:255',
            'settings.redirect_uri' => ['sometimes', 'nullable', 'url:http,https', 'max:2048', $this->redirectUriRule()],
            'settings.scopes' => 'sometimes|array|max:20',
            'settings.scopes.*' => ['string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'settings.allowed_groups' => 'sometimes|array|max:100',
            'settings.allowed_groups.*' => 'string|max:255',
            'settings.admin_groups' => 'sometimes|array|max:100',
            'settings.admin_groups.*' => 'string|max:255',
            'settings.client_secret' => 'prohibited',
            'change_reason' => ['required', 'in:identity_provider_configuration,identity_provider_emergency_disable'],
        ]);

        $this->stepUp->assertSatisfied($request, 'identity_provider_configuration_changed');

        DB::transaction(function () use ($request, $type, $validated): void {
            $row = AuthProviderSetting::query()->firstOrNew(['provider_type' => $type]);
            $beforeEnabled = $row->exists ? (bool) $row->is_enabled : null;
            $beforeDisplayName = $row->display_name;
            $beforeSettings = $row->settings ?? [];
            $merged = array_intersect_key(
                array_merge($beforeSettings, $validated['settings'] ?? []),
                array_flip(self::EDITABLE_SETTING_KEYS),
            ); // purge legacy secrets and undeclared provider metadata
            $this->assertCandidateUrls($merged);

            $row->fill([
                'display_name' => $validated['display_name'] ?? $row->display_name ?? 'Sign in with Authentik',
                'is_enabled' => $validated['is_enabled'] ?? $row->is_enabled ?? false,
                'settings' => $merged,
                'updated_by' => $request->user()?->id,
            ])->save();

            $this->audit->record('administration.auth_provider.updated', 'administration', 'success', [
                'request' => $request,
                'reason' => $validated['change_reason'],
                'target_type' => 'auth_provider',
                'target_id' => $type,
                'changes' => [
                    'provider_enabled' => ['from' => $beforeEnabled, 'to' => (bool) $row->is_enabled],
                    'display_name_changed' => ['from' => false, 'to' => $beforeDisplayName !== $row->display_name],
                    'settings_changed' => ['from' => false, 'to' => $beforeSettings !== $merged],
                ],
                'metadata' => [
                    'provider_type' => $type,
                    'changed_fields' => array_values(array_diff(array_keys($validated), ['change_reason'])),
                ],
            ]);
        });

        return $this->show($type);
    }

    public function diagnose(Request $request, string $type, OidcDiscoveryService $discovery): JsonResponse
    {
        Gate::authorize('viewIdentity');
        abort_unless($type === 'oidc', 404);

        $discovery->flush();
        try {
            $diagnostics = $discovery->diagnostics();
        } catch (OidcException $exception) {
            $this->audit->record('administration.auth_provider.diagnostic', 'administration', 'failure', [
                'request' => $request,
                'reason' => $exception->reason,
                'target_type' => 'auth_provider',
                'target_id' => $type,
                'http_status' => 422,
                'metadata' => ['provider_type' => $type],
            ]);

            return response()->json([
                'status' => 'failed',
                'reason' => $exception->reason,
                'checkedAt' => now()->toIso8601String(),
            ], 422);
        }

        $this->audit->record('administration.auth_provider.diagnostic', 'administration', 'success', [
            'request' => $request,
            'target_type' => 'auth_provider',
            'target_id' => $type,
            'http_status' => 200,
            'metadata' => ['provider_type' => $type],
        ]);

        return response()->json([
            ...$diagnostics,
            'checkedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function publicSettings(array $settings): array
    {
        return array_intersect_key($settings, array_flip(self::EDITABLE_SETTING_KEYS));
    }

    private function outboundUrlRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            try {
                $this->urlPolicy->assertSafeOutboundUrl((string) $value);
            } catch (UnsafeOidcUrl $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    private function redirectUriRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            try {
                $this->urlPolicy->assertAllowedRedirectUri((string) $value);
            } catch (UnsafeOidcUrl $exception) {
                $fail($exception->getMessage());
            }
        };
    }

    /** @param array<string, mixed> $settings */
    private function assertCandidateUrls(array $settings): void
    {
        $discoveryUrl = trim((string) ($settings['discovery_url'] ?? config('services.oidc.discovery_url', '')));
        $redirectUri = trim((string) ($settings['redirect_uri'] ?? config('services.oidc.redirect_uri', '')));

        try {
            if ($discoveryUrl !== '') {
                $this->urlPolicy->assertSafeOutboundUrl($discoveryUrl);
            }
            if ($redirectUri !== '') {
                $this->urlPolicy->assertAllowedRedirectUri($redirectUri);
            }
        } catch (UnsafeOidcUrl $exception) {
            throw ValidationException::withMessages([
                'settings' => [$exception->getMessage()],
            ]);
        }
    }
}
