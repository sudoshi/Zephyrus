<?php

namespace App\Services\Eddy;

use App\Models\Eddy\EddyProviderProfile;
use App\Models\Eddy\EddySurfacePolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Provider-policy engine for Eddy — port of Parthenon's AbbyProviderPolicyService.
 *
 * Decides, per surface, which provider profile (local Ollama/MedGemma vs frontier
 * Claude) a turn may use, enforcing capability requirements, cloud-allow flags and
 * the PHI-egress gate. Fully domain-agnostic except the SURFACES list and
 * surfaceRequirements() map.
 *
 * SECURITY DEVIATION FROM ABBY: provider API keys are NOT resolved here. They live
 * only in the Eddy FastAPI service's own env. payloadForProfile() emits non-secret
 * routing hints (provider/model/mode/base_url/limits); Eddy resolves the secret.
 */
class EddyProviderPolicyService
{
    public const CAPABILITIES = [
        'chat',
        'streaming',
        'structured_output',
        'json_mode',
        'tool_calling',
        'agent_loop',
        'long_context',
        'vision',
        'ops_rag',
        'patient_context_local_only',
    ];

    public const ENTITLEMENTS = [
        'local',
        'org_api_key',
        'user_api_key',
        'acumenus_managed_api',
    ];

    public const MODES = [
        'local_only',
        'cloud_only',
        'local_first',
        'cloud_first',
        'auto_by_complexity',
        'auto_by_budget',
        'disabled',
    ];

    public const SURFACES = [
        'chat',
        'rtdc',
        'ed',
        'periop',
        'transport',
        'evs',
        'staffing',
        'improvement',
        'command_center',
        'eddy_agent',
    ];

    public const TRANSPORTS = [
        'ollama_chat',
        'anthropic_messages',
        'openai_responses',
        'openai_compatible_chat',
        'anthropic_compatible_proxy',
    ];

    private const OPENAI_COMPATIBLE_BASE_URLS = [
        'deepseek' => 'https://api.deepseek.com',
        'mistral' => 'https://api.mistral.ai/v1',
        'moonshot' => 'https://api.moonshot.cn/v1',
        'qwen' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public function surfaceRequirements(): array
    {
        return [
            'chat' => ['chat', 'streaming'],
            'rtdc' => ['chat', 'tool_calling'],
            'ed' => ['chat', 'tool_calling'],
            'periop' => ['chat', 'tool_calling'],
            'transport' => ['chat', 'tool_calling'],
            'evs' => ['chat', 'tool_calling'],
            'staffing' => ['chat', 'tool_calling'],
            'improvement' => ['chat', 'long_context'],
            'command_center' => ['chat', 'structured_output'],
            'eddy_agent' => ['agent_loop', 'tool_calling'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return [
            'capabilities' => self::CAPABILITIES,
            'entitlements' => self::ENTITLEMENTS,
            'modes' => self::MODES,
            'surfaces' => self::SURFACES,
            'transports' => self::TRANSPORTS,
            'surface_requirements' => $this->surfaceRequirements(),
            'presets' => $this->presets(),
            'readiness' => $this->readiness(),
        ];
    }

    /**
     * Per-profile readiness for admin diagnostics. Secret-safe (no key material).
     *
     * @return array<int, array<string, mixed>>
     */
    public function readiness(): array
    {
        if (! $this->tablesExist()) {
            return [];
        }

        return EddyProviderProfile::query()
            ->orderBy('profile_id')
            ->get()
            ->map(function (EddyProviderProfile $profile): array {
                return [
                    'profile_id' => $profile->profile_id,
                    'provider_type' => $profile->provider_type,
                    'transport' => $profile->transport,
                    'entitlement_type' => $profile->entitlement_type,
                    'state' => $this->readinessState($profile),
                    'agent_capable' => in_array('agent_loop', array_values($profile->capabilities ?? []), true)
                        && in_array('tool_calling', array_values($profile->capabilities ?? []), true),
                ];
            })
            ->all();
    }

    private function readinessState(EddyProviderProfile $profile): string
    {
        if (! $profile->is_enabled) {
            return 'disabled';
        }

        // Cloud profiles: the api_key lives in the Eddy service env, so Laravel
        // cannot probe it directly. Readiness here means "configured + enabled";
        // live key/Ollama reachability is the Eddy /health responsibility.
        if ($this->isCloudProfile($profile)) {
            return $profile->model ? 'ready' : 'cloud_model_unconfigured';
        }

        return $profile->model ? 'ready' : 'local_model_unconfigured';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    public function validateProfileAttributes(array $attributes): array
    {
        $errors = [];
        $transport = (string) ($attributes['transport'] ?? '');
        $entitlement = (string) ($attributes['entitlement_type'] ?? 'local');
        $providerType = (string) ($attributes['provider_type'] ?? '');
        $capabilities = array_values($attributes['capabilities'] ?? []);

        $unknownCapabilities = array_values(array_diff($capabilities, self::CAPABILITIES));
        if ($unknownCapabilities !== []) {
            $errors[] = 'unknown_capabilities:'.implode(',', $unknownCapabilities);
        }

        if (! in_array($transport, self::TRANSPORTS, true)) {
            $errors[] = 'unsupported_transport';
        }

        if (! in_array($entitlement, self::ENTITLEMENTS, true)) {
            $errors[] = 'unsupported_entitlement';
        }

        if ($transport === 'ollama_chat' && empty($attributes['base_url'])) {
            $errors[] = 'base_url_required_for_ollama';
        }

        if ($transport === 'openai_compatible_chat' && empty($attributes['base_url'])) {
            $errors[] = 'base_url_required_for_openai_compatible';
        }

        if ($transport !== 'ollama_chat' && $entitlement === 'local') {
            $errors[] = 'cloud_transport_requires_non_local_entitlement';
        }

        if ($transport === 'ollama_chat' && $entitlement !== 'local') {
            $errors[] = 'ollama_transport_requires_local_entitlement';
        }

        if ($providerType === 'openai_compatible' && $transport === 'openai_compatible_chat') {
            $errors[] = 'openai_compatible_profile_requires_concrete_provider_type';
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    public function validateSurfacePolicy(EddySurfacePolicy $policy): array
    {
        $errors = [];

        if (! in_array($policy->surface, self::SURFACES, true)) {
            $errors[] = 'unsupported_surface';
        }

        if (! in_array($policy->provider_mode, self::MODES, true)) {
            $errors[] = 'unsupported_provider_mode';
        }

        if ($policy->provider_mode === 'disabled') {
            return $errors;
        }

        $profile = $policy->default_profile_id
            ? EddyProviderProfile::where('profile_id', $policy->default_profile_id)->first()
            : null;

        if ($profile === null) {
            $errors[] = 'default_profile_missing';
        } else {
            $errors = array_merge($errors, $this->validateProfileForSurface($profile, $policy));
        }

        foreach (($policy->fallback_profile_ids ?? []) as $profileId) {
            $fallback = EddyProviderProfile::where('profile_id', $profileId)->first();
            if ($fallback === null) {
                $errors[] = "fallback_profile_missing:{$profileId}";
            } else {
                $errors = array_merge($errors, array_map(
                    fn (string $error): string => "fallback_profile_invalid:{$profileId}:{$error}",
                    $this->validateProfileForSurface($fallback, $policy),
                ));
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    public function validateProfileForSurface(EddyProviderProfile $profile, EddySurfacePolicy $policy): array
    {
        $errors = [];
        $capabilities = array_values($profile->capabilities ?? []);
        $required = array_values($policy->required_capabilities ?: ($this->surfaceRequirements()[$policy->surface] ?? ['chat']));
        $missing = array_values(array_diff($required, $capabilities));

        if (! $profile->is_enabled) {
            $errors[] = 'profile_disabled';
        }

        if ($missing !== []) {
            $errors[] = 'missing_capabilities:'.implode(',', $missing);
        }

        if ($this->isCloudProfile($profile) && ! $policy->allow_cloud) {
            $errors[] = 'cloud_not_allowed';
        }

        $patientLevelAllowed = (bool) Arr::get($profile->safety ?? [], 'patient_level_context_allowed', false);
        if (
            $this->isCloudProfile($profile)
            && $policy->never_send_phi_to_cloud
            && ! $patientLevelAllowed
        ) {
            $errors[] = 'patient_context_not_cloud_safe';
        }

        return $errors;
    }

    /**
     * The production payload path: resolve the first valid profile for a surface
     * (default, then each fallback). Mirrors simulateRoute() so the runtime and
     * the admin simulator agree on which profile a turn actually uses.
     *
     * @return array<string, mixed>|null
     */
    public function payloadForSurface(string $surface): ?array
    {
        if (! $this->tablesExist()) {
            return null;
        }

        $policy = EddySurfacePolicy::where('surface', $surface)->first();
        if ($policy === null) {
            return null;
        }

        if ($policy->provider_mode === 'disabled') {
            return [
                'provider_type' => 'ollama',
                'profile_id' => $policy->default_profile_id ?: 'local-medgemma',
                'mode' => 'disabled',
                'model' => '',
                'entitlement' => 'local',
                'settings' => [],
            ];
        }

        $candidateIds = array_merge(
            $policy->default_profile_id ? [$policy->default_profile_id] : [],
            $policy->fallback_profile_ids ?? [],
        );

        foreach ($candidateIds as $profileId) {
            $profile = EddyProviderProfile::where('profile_id', $profileId)->first();
            if ($profile !== null && $this->validateProfileForSurface($profile, $policy) === []) {
                return $this->payloadForProfile($profile, $policy->provider_mode);
            }
        }

        return null;
    }

    /**
     * Named policy presets — starting points a super-admin applies, then adjusts.
     *
     * @return array<string, array<string, mixed>>
     */
    public function presets(): array
    {
        return [
            'clinical_local_only' => [
                'label' => 'Clinical local-only (patient-level surfaces)',
                'provider_mode' => 'local_only',
                'allow_cloud' => false,
                'never_send_phi_to_cloud' => true,
            ],
            'phi_free_cloud_first' => [
                'label' => 'PHI-free aggregates, cloud-first',
                'provider_mode' => 'cloud_first',
                'allow_cloud' => true,
                'never_send_phi_to_cloud' => true,
            ],
            'local_first_cloud_summaries' => [
                'label' => 'Local-first with cloud summaries',
                'provider_mode' => 'local_first',
                'allow_cloud' => true,
                'never_send_phi_to_cloud' => true,
            ],
            'byo_api_key' => [
                'label' => 'BYO API key',
                'provider_mode' => 'cloud_first',
                'allow_cloud' => true,
                'never_send_phi_to_cloud' => true,
                'entitlement' => 'user_api_key',
            ],
            'agents_frontier_default' => [
                'label' => 'Agents frontier-default with approvals',
                'provider_mode' => 'cloud_first',
                'allow_cloud' => true,
                'never_send_phi_to_cloud' => true,
                'surface' => 'eddy_agent',
            ],
            'agents_local_only' => [
                'label' => 'Agents read-only local',
                'provider_mode' => 'local_only',
                'allow_cloud' => false,
                'never_send_phi_to_cloud' => true,
                'surface' => 'eddy_agent',
            ],
        ];
    }

    /**
     * Emit the non-secret routing hints for a profile. NO api_key — that lives in
     * the Eddy service env.
     *
     * @return array<string, mixed>
     */
    public function payloadForProfile(EddyProviderProfile $profile, string $mode): array
    {
        $limits = $profile->limits ?? [];
        $baseUrl = $profile->base_url
            ?: (self::OPENAI_COMPATIBLE_BASE_URLS[strtolower($profile->provider_type)] ?? null);

        return [
            'provider_type' => strtolower($profile->provider_type),
            'profile_id' => $profile->profile_id,
            'mode' => $mode,
            'model' => $profile->model,
            'entitlement' => $profile->entitlement_type,
            'settings' => $this->onlyNonEmpty([
                'base_url' => $baseUrl,
                'timeout' => $limits['timeout'] ?? $limits['timeout_seconds'] ?? null,
                'max_output_tokens' => $limits['max_output_tokens'] ?? null,
                'monthly_budget_usd' => $limits['monthly_budget_usd'] ?? null,
                'entitlement' => $profile->entitlement_type,
            ]),
        ];
    }

    /**
     * Dry-run the route for the super-admin simulator.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function simulateRoute(array $request): array
    {
        $surface = (string) ($request['surface'] ?? 'chat');
        $message = (string) ($request['message'] ?? '');
        $policy = EddySurfacePolicy::where('surface', $surface)->first();

        if ($policy === null) {
            return [
                'configured' => false,
                'surface' => $surface,
                'reason' => 'surface_policy_missing',
                'will_call_paid_provider' => false,
                'blocked_reasons' => ['surface_policy_missing'],
            ];
        }

        if ($policy->provider_mode === 'disabled') {
            return [
                'configured' => true,
                'surface' => $surface,
                'provider_mode' => $policy->provider_mode,
                'reason' => 'provider_disabled',
                'will_call_paid_provider' => false,
                'blocked_reasons' => ['provider_disabled'],
            ];
        }

        $profile = EddyProviderProfile::where('profile_id', (string) $policy->default_profile_id)->first();
        $blockedReasons = $profile === null
            ? ['default_profile_missing']
            : $this->validateProfileForSurface($profile, $policy);
        $selectedProfile = $profile;
        $fallbackUsed = false;

        if ($blockedReasons !== []) {
            foreach (($policy->fallback_profile_ids ?? []) as $profileId) {
                $candidate = EddyProviderProfile::where('profile_id', $profileId)->first();
                if ($candidate !== null && $this->validateProfileForSurface($candidate, $policy) === []) {
                    $selectedProfile = $candidate;
                    $fallbackUsed = true;
                    break;
                }
            }
        }

        return [
            'configured' => true,
            'surface' => $surface,
            'provider_mode' => $policy->provider_mode,
            'message_length' => strlen($message),
            'requested_profile_id' => $policy->default_profile_id,
            'selected_profile' => $selectedProfile?->only([
                'profile_id',
                'display_name',
                'provider_type',
                'transport',
                'entitlement_type',
                'model',
                'is_enabled',
            ]),
            'fallback_profile_ids' => $policy->fallback_profile_ids ?? [],
            'fallback_used' => $fallbackUsed,
            'reason' => $blockedReasons === [] ? $policy->provider_mode : $blockedReasons[0],
            'blocked_reasons' => $blockedReasons,
            'will_call_paid_provider' => $selectedProfile !== null
                && $this->isCloudProfile($selectedProfile)
                && $blockedReasons === [],
            'estimated_budget_impact' => $selectedProfile !== null && $this->isCloudProfile($selectedProfile)
                ? 'unknown_until_provider_response'
                : 'zero_api_cost',
        ];
    }

    public function isCloudProfile(EddyProviderProfile $profile): bool
    {
        return $profile->entitlement_type !== 'local' && $profile->transport !== 'ollama_chat';
    }

    /**
     * @return array<int, array{surface: string, role: string, index?: int}>
     */
    public function profilePolicyReferences(string $profileId): array
    {
        return EddySurfacePolicy::query()
            ->orderBy('surface')
            ->get()
            ->flatMap(function (EddySurfacePolicy $policy) use ($profileId): array {
                $references = [];

                if ($policy->default_profile_id === $profileId) {
                    $references[] = [
                        'surface' => $policy->surface,
                        'role' => 'default_profile_id',
                    ];
                }

                foreach (($policy->fallback_profile_ids ?? []) as $index => $fallbackProfileId) {
                    if ((string) $fallbackProfileId === $profileId) {
                        $references[] = [
                            'surface' => $policy->surface,
                            'role' => 'fallback_profile_ids',
                            'index' => $index,
                        ];
                    }
                }

                return $references;
            })
            ->values()
            ->all();
    }

    public function profileIsReferenced(string $profileId): bool
    {
        return $this->profilePolicyReferences($profileId) !== [];
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('eddy.eddy_provider_profiles')
            && Schema::hasTable('eddy.eddy_surface_policies');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function onlyNonEmpty(array $values): array
    {
        return array_filter(
            $values,
            fn ($value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<int, string>  $errors
     *
     * @throws ValidationException
     */
    public function throwIfErrors(array $errors, string $key = 'policy'): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages([$key => $errors]);
        }
    }
}
