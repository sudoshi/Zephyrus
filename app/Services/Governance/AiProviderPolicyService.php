<?php

namespace App\Services\Governance;

use App\Authorization\GovernedAction;
use App\Models\Eddy\EddyProviderProfile;
use App\Models\Eddy\EddySurfacePolicy;
use App\Models\Governance\AiProviderPolicyVersion;
use App\Models\Governance\GovernedChangeRequest;
use App\Services\Eddy\EddyProviderPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versioned, dual-controlled Zephyrus/Eddy AI provider policy. One canonical
 * policy document ('eddy') governs provider/model capability, fallback order,
 * cost limits, PHI eligibility, region residency, and surface routing. The
 * eddy.* rows are only a projection; changes require an immutable proposal
 * version plus an independently approved governed change bound to its exact
 * hash. Rollback is a NEW version referencing a prior one. No secrets and no
 * prompt or patient content ever enter this ledger.
 */
final class AiProviderPolicyService
{
    public const POLICY_KEY = 'eddy';

    private const PROFILE_ID_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,99}$/';

    private const REGION_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,79}$/';

    public function __construct(
        private readonly GovernedChangeService $governance,
        private readonly EddyProviderPolicyService $policy,
    ) {}

    /**
     * The effective policy document projected from the eddy.* rows.
     *
     * @return array{profiles: list<array<string, mixed>>, surfaces: list<array<string, mixed>>}
     */
    public function currentDocument(): array
    {
        $profiles = EddyProviderProfile::query()->orderBy('profile_id')->get()
            ->map(fn (EddyProviderProfile $profile): array => $this->profileDocument($profile))
            ->values()
            ->all();
        $surfaces = EddySurfacePolicy::query()->orderBy('surface')->get()
            ->map(fn (EddySurfacePolicy $policy): array => $this->surfaceDocument($policy))
            ->values()
            ->all();

        return ['profiles' => $profiles, 'surfaces' => $surfaces];
    }

    /**
     * Validate a proposed policy document with the existing Eddy policy
     * service's catalog contract (capabilities, transports, entitlements,
     * modes, surface requirements) plus document-level integrity checks.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public function validateDocument(array $document): array
    {
        $errors = [];
        $profiles = is_array($document['profiles'] ?? null) ? $document['profiles'] : null;
        $surfaces = is_array($document['surfaces'] ?? null) ? $document['surfaces'] : null;
        if ($profiles === null || $surfaces === null) {
            return ['document_shape_invalid'];
        }
        if (count($profiles) > 20 || count($surfaces) > count(EddyProviderPolicyService::SURFACES)) {
            $errors[] = 'document_out_of_bounds';
        }

        $profilesById = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                return ['document_shape_invalid'];
            }
            $profileId = (string) ($profile['profile_id'] ?? '');
            if (preg_match(self::PROFILE_ID_PATTERN, $profileId) !== 1) {
                $errors[] = 'profile_id_invalid';

                continue;
            }
            if (isset($profilesById[$profileId])) {
                $errors[] = "profile_duplicate:{$profileId}";
            }
            $profilesById[$profileId] = $profile;

            foreach ($this->policy->validateProfileAttributes($profile) as $error) {
                $errors[] = "profile_invalid:{$profileId}:{$error}";
            }
            $displayName = (string) ($profile['display_name'] ?? '');
            if ($displayName === '' || mb_strlen($displayName) > 200) {
                $errors[] = "profile_invalid:{$profileId}:display_name_required";
            }
            $region = $profile['region'] ?? null;
            if ($region !== null && (! is_string($region) || preg_match(self::REGION_PATTERN, $region) !== 1)) {
                $errors[] = "profile_invalid:{$profileId}:region_invalid";
            }
            $budget = $profile['limits']['monthly_budget_usd'] ?? null;
            if ($budget !== null && (! is_numeric($budget) || (float) $budget < 0 || (float) $budget > 1000000)) {
                $errors[] = "profile_invalid:{$profileId}:monthly_budget_out_of_bounds";
            }
        }

        $seenSurfaces = [];
        foreach ($surfaces as $surface) {
            if (! is_array($surface)) {
                return ['document_shape_invalid'];
            }
            $surfaceKey = (string) ($surface['surface'] ?? '');
            if (! in_array($surfaceKey, EddyProviderPolicyService::SURFACES, true)) {
                $errors[] = 'surface_unsupported';

                continue;
            }
            if (isset($seenSurfaces[$surfaceKey])) {
                $errors[] = "surface_duplicate:{$surfaceKey}";
            }
            $seenSurfaces[$surfaceKey] = true;

            if (! in_array((string) ($surface['provider_mode'] ?? ''), EddyProviderPolicyService::MODES, true)) {
                $errors[] = "surface_invalid:{$surfaceKey}:unsupported_provider_mode";

                continue;
            }
            if (($surface['provider_mode'] ?? '') === 'disabled') {
                continue;
            }

            $candidateIds = array_merge(
                filled($surface['default_profile_id'] ?? null) ? [(string) $surface['default_profile_id']] : [],
                array_map('strval', is_array($surface['fallback_profile_ids'] ?? null) ? $surface['fallback_profile_ids'] : []),
            );
            if ($candidateIds === []) {
                $errors[] = "surface_invalid:{$surfaceKey}:default_profile_missing";
            }
            foreach ($candidateIds as $candidateId) {
                $candidate = $profilesById[$candidateId] ?? null;
                if ($candidate === null) {
                    $errors[] = "surface_invalid:{$surfaceKey}:profile_missing:{$candidateId}";

                    continue;
                }
                foreach ($this->validateProfileForSurfaceDocument($candidate, $surface) as $error) {
                    $errors[] = "surface_invalid:{$surfaceKey}:{$candidateId}:{$error}";
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Compute the would-be document for a proposal or rollback without writes.
     *
     * @param  array<string, mixed>|null  $document
     * @return array<string, mixed>
     */
    public function preview(?array $document, ?int $rollbackToVersionNumber = null): array
    {
        $current = $this->canonicalize($this->currentDocument());

        if ($rollbackToVersionNumber !== null) {
            $target = $this->versionByNumber($rollbackToVersionNumber);
            $proposed = $this->canonicalize($target->policy);
        } else {
            $proposed = $this->canonicalize($this->normalizeDocument($document ?? []));
        }

        return [
            'policyKey' => self::POLICY_KEY,
            'current' => $current,
            'proposed' => $proposed,
            'changedSections' => $this->changedSections($current, $proposed),
            'errors' => $this->validateDocument($proposed),
            'policySha256' => $this->hash($proposed),
            'rollbackToVersionNumber' => $rollbackToVersionNumber,
        ];
    }

    /**
     * Create an immutable proposal version plus its governed change request.
     *
     * @param  array<string, mixed>|null  $document
     * @return array{version: AiProviderPolicyVersion, change: GovernedChangeRequest}
     */
    public function requestChange(
        Request $request,
        ?array $document,
        string $reason,
        ?int $rollbackToVersionNumber = null,
    ): array {
        $preview = $this->preview($document, $rollbackToVersionNumber);
        if ($preview['errors'] !== []) {
            throw ValidationException::withMessages(['policy' => $preview['errors']]);
        }
        if ($preview['changedSections'] === []) {
            throw ValidationException::withMessages([
                'policy' => ['The proposed AI provider policy is identical to the effective document.'],
            ]);
        }

        return DB::transaction(function () use ($request, $reason, $preview): array {
            $rollbackTargetId = $preview['rollbackToVersionNumber'] !== null
                ? $this->versionByNumber((int) $preview['rollbackToVersionNumber'])->getKey()
                : null;

            $version = $this->insertVersion(
                $preview['proposed'],
                'proposal',
                $reason,
                $request->user()?->getKey(),
                rolledBackToVersionId: $rollbackTargetId,
            );

            $change = $this->governance->requestChange(
                $request,
                GovernedAction::ApplyAiProviderPolicy,
                'ai_provider_policy',
                self::POLICY_KEY,
                $reason,
                (string) $version->policy_sha256,
                metadata: [
                    'policy_key' => self::POLICY_KEY,
                    'policy_version' => (int) $version->version_number,
                    'rolled_back_to_version' => $preview['rollbackToVersionNumber'],
                    'changed_fields' => $preview['changedSections'],
                ],
            );

            return ['version' => $version, 'change' => $change];
        });
    }

    /**
     * Apply an approved proposal: append the effective version and project it
     * onto the eddy.* provider and surface rows. Profiles absent from the
     * governed document are disabled, never deleted.
     */
    public function applyApproved(Request $request, string $changeRequestUuid): AiProviderPolicyVersion
    {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        $proposal = $this->proposalForChange($change);

        return $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::ApplyAiProviderPolicy,
            'ai_provider_policy',
            self::POLICY_KEY,
            (string) $proposal->policy_sha256,
            function () use ($request, $proposal, $change): AiProviderPolicyVersion {
                $errors = $this->validateDocument($proposal->policy);
                if ($errors !== []) {
                    throw ValidationException::withMessages(['policy' => $errors]);
                }

                $applied = $this->insertVersion(
                    $proposal->policy,
                    $proposal->rolled_back_to_version_id !== null ? 'rollback' : 'governed_application',
                    (string) $change->reason,
                    $request->user()?->getKey(),
                    governedChangeRequestUuid: (string) $change->getKey(),
                    rolledBackToVersionId: $proposal->rolled_back_to_version_id,
                );
                $this->project($applied->policy, $request->user()?->getKey());

                return $applied;
            },
        );
    }

    /**
     * PHI-safe dry-run route simulation. Accepts ONLY a surface descriptor —
     * never prompt text or patient content — and returns which provider/model
     * the effective policy would route to and why, plus the surface's PHI
     * posture. Stores nothing; the controller records a non-content audit event.
     *
     * @return array<string, mixed>
     */
    public function simulateRoute(string $surface): array
    {
        $simulation = $this->policy->simulateRoute(['surface' => $surface]);
        unset($simulation['message_length']);

        $policyRow = EddySurfacePolicy::query()->where('surface', $surface)->first();

        return $simulation + [
            'phi_posture' => [
                'never_send_phi_to_cloud' => (bool) ($policyRow?->never_send_phi_to_cloud ?? true),
                'allow_cloud' => (bool) ($policyRow?->allow_cloud ?? false),
                'cloud_kill_switch_enabled' => ! (bool) config('eddy.allow_cloud', false),
            ],
        ];
    }

    public function effectiveVersion(): ?AiProviderPolicyVersion
    {
        return AiProviderPolicyVersion::query()
            ->where('policy_key', self::POLICY_KEY)
            ->where('change_kind', '!=', 'proposal')
            ->orderByDesc('version_number')
            ->first();
    }

    /**
     * Drift: the projected eddy.* rows no longer hash to the effective version
     * (for example a console write bypassed governance). Reported, not hidden.
     */
    public function drift(): bool
    {
        $effective = $this->effectiveVersion();
        if ($effective === null) {
            return AiProviderPolicyVersion::query()->where('policy_key', self::POLICY_KEY)->exists()
                || EddyProviderProfile::query()->exists()
                || EddySurfacePolicy::query()->exists();
        }

        return ! hash_equals(
            (string) $effective->policy_sha256,
            $this->hash($this->canonicalize($this->currentDocument())),
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 20): array
    {
        return AiProviderPolicyVersion::query()
            ->with('author:id,name,username')
            ->where('policy_key', self::POLICY_KEY)
            ->orderByDesc('version_number')
            ->limit($limit)
            ->get()
            ->map(fn (AiProviderPolicyVersion $version): array => [
                'versionId' => (int) $version->getKey(),
                'versionNumber' => (int) $version->version_number,
                'changeKind' => (string) $version->change_kind,
                'changeReason' => (string) $version->change_reason,
                'policySha256' => (string) $version->policy_sha256,
                'profileCount' => count($version->policy['profiles'] ?? []),
                'surfaceCount' => count($version->policy['surfaces'] ?? []),
                'rolledBackToVersionId' => $version->rolled_back_to_version_id,
                'effectiveAtIso' => $version->effective_at?->toIso8601String(),
                'createdBy' => $version->author?->only(['id', 'name', 'username']),
                'governedChangeRequestUuid' => $version->governed_change_request_uuid,
            ])
            ->all();
    }

    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        return $this->governance->hashPayload($payload);
    }

    /** @return array<string, mixed> */
    private function profileDocument(EddyProviderProfile $profile): array
    {
        $limits = $profile->limits ?? [];

        return $this->canonicalize([
            'profile_id' => (string) $profile->profile_id,
            'display_name' => (string) $profile->display_name,
            'provider_type' => (string) $profile->provider_type,
            'transport' => (string) $profile->transport,
            'entitlement_type' => (string) $profile->entitlement_type,
            'model' => (string) $profile->model,
            'base_url' => $profile->base_url,
            'region' => $profile->region,
            'is_enabled' => (bool) $profile->is_enabled,
            'capabilities' => array_values($profile->capabilities ?? []),
            'safety' => [
                'patient_level_context_allowed' => (bool) (($profile->safety ?? [])['patient_level_context_allowed'] ?? false),
            ],
            'limits' => [
                'timeout' => isset($limits['timeout']) ? (int) $limits['timeout'] : null,
                'max_output_tokens' => isset($limits['max_output_tokens']) ? (int) $limits['max_output_tokens'] : null,
                'monthly_budget_usd' => isset($limits['monthly_budget_usd']) ? (float) $limits['monthly_budget_usd'] : null,
            ],
            'fallback_profile_ids' => array_values(array_map('strval', $profile->fallback_profile_ids ?? [])),
        ]);
    }

    /** @return array<string, mixed> */
    private function surfaceDocument(EddySurfacePolicy $policy): array
    {
        return $this->canonicalize([
            'surface' => (string) $policy->surface,
            'provider_mode' => (string) $policy->provider_mode,
            'default_profile_id' => $policy->default_profile_id,
            'fallback_profile_ids' => array_values(array_map('strval', $policy->fallback_profile_ids ?? [])),
            'allow_cloud' => (bool) $policy->allow_cloud,
            'never_send_phi_to_cloud' => (bool) $policy->never_send_phi_to_cloud,
            'required_capabilities' => array_values(array_map('strval', $policy->required_capabilities ?? [])),
        ]);
    }

    /**
     * Normalize an inbound document to the exact governed shape (unknown keys
     * dropped, types coerced) so hashing is deterministic and nothing beyond
     * the declared policy fields can ride along.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function normalizeDocument(array $document): array
    {
        $profiles = [];
        foreach (is_array($document['profiles'] ?? null) ? $document['profiles'] : [] as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $limits = is_array($profile['limits'] ?? null) ? $profile['limits'] : [];
            $profiles[] = $this->canonicalize([
                'profile_id' => (string) ($profile['profile_id'] ?? ''),
                'display_name' => (string) ($profile['display_name'] ?? ''),
                'provider_type' => (string) ($profile['provider_type'] ?? ''),
                'transport' => (string) ($profile['transport'] ?? ''),
                'entitlement_type' => (string) ($profile['entitlement_type'] ?? 'local'),
                'model' => (string) ($profile['model'] ?? ''),
                'base_url' => filled($profile['base_url'] ?? null) ? (string) $profile['base_url'] : null,
                'region' => filled($profile['region'] ?? null) ? (string) $profile['region'] : null,
                'is_enabled' => (bool) ($profile['is_enabled'] ?? true),
                'capabilities' => array_values(array_map('strval', is_array($profile['capabilities'] ?? null) ? $profile['capabilities'] : [])),
                'safety' => [
                    'patient_level_context_allowed' => (bool) (($profile['safety'] ?? [])['patient_level_context_allowed'] ?? false),
                ],
                'limits' => [
                    'timeout' => isset($limits['timeout']) ? (int) $limits['timeout'] : null,
                    'max_output_tokens' => isset($limits['max_output_tokens']) ? (int) $limits['max_output_tokens'] : null,
                    'monthly_budget_usd' => isset($limits['monthly_budget_usd']) ? (float) $limits['monthly_budget_usd'] : null,
                ],
                'fallback_profile_ids' => array_values(array_map('strval', is_array($profile['fallback_profile_ids'] ?? null) ? $profile['fallback_profile_ids'] : [])),
            ]);
        }
        usort($profiles, fn (array $a, array $b): int => strcmp($a['profile_id'], $b['profile_id']));

        $surfaces = [];
        foreach (is_array($document['surfaces'] ?? null) ? $document['surfaces'] : [] as $surface) {
            if (! is_array($surface)) {
                continue;
            }
            $surfaces[] = $this->canonicalize([
                'surface' => (string) ($surface['surface'] ?? ''),
                'provider_mode' => (string) ($surface['provider_mode'] ?? 'local_only'),
                'default_profile_id' => filled($surface['default_profile_id'] ?? null) ? (string) $surface['default_profile_id'] : null,
                'fallback_profile_ids' => array_values(array_map('strval', is_array($surface['fallback_profile_ids'] ?? null) ? $surface['fallback_profile_ids'] : [])),
                'allow_cloud' => (bool) ($surface['allow_cloud'] ?? false),
                'never_send_phi_to_cloud' => (bool) ($surface['never_send_phi_to_cloud'] ?? true),
                'required_capabilities' => array_values(array_map('strval', is_array($surface['required_capabilities'] ?? null) ? $surface['required_capabilities'] : [])),
            ]);
        }
        usort($surfaces, fn (array $a, array $b): int => strcmp($a['surface'], $b['surface']));

        return ['profiles' => $profiles, 'surfaces' => $surfaces];
    }

    /**
     * Document-level port of EddyProviderPolicyService::validateProfileForSurface
     * evaluated against the PROPOSED profiles rather than the currently saved rows.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $surface
     * @return list<string>
     */
    private function validateProfileForSurfaceDocument(array $profile, array $surface): array
    {
        $errors = [];
        $capabilities = array_values(array_map('strval', is_array($profile['capabilities'] ?? null) ? $profile['capabilities'] : []));
        $required = array_values(array_map('strval', is_array($surface['required_capabilities'] ?? null) && $surface['required_capabilities'] !== []
            ? $surface['required_capabilities']
            : ($this->policy->surfaceRequirements()[(string) $surface['surface']] ?? ['chat'])));
        $missing = array_values(array_diff($required, $capabilities));

        if (! (bool) ($profile['is_enabled'] ?? true)) {
            $errors[] = 'profile_disabled';
        }
        if ($missing !== []) {
            $errors[] = 'missing_capabilities:'.implode(',', $missing);
        }

        $isCloud = ($profile['entitlement_type'] ?? 'local') !== 'local'
            && ($profile['transport'] ?? '') !== 'ollama_chat';
        if ($isCloud && ! (bool) ($surface['allow_cloud'] ?? false)) {
            $errors[] = 'cloud_not_allowed';
        }
        $patientLevelAllowed = (bool) (($profile['safety'] ?? [])['patient_level_context_allowed'] ?? false);
        if ($isCloud && (bool) ($surface['never_send_phi_to_cloud'] ?? true) && ! $patientLevelAllowed) {
            $errors[] = 'patient_context_not_cloud_safe';
        }

        return $errors;
    }

    private function proposalForChange(GovernedChangeRequest $change): AiProviderPolicyVersion
    {
        $proposal = AiProviderPolicyVersion::query()
            ->where('policy_key', self::POLICY_KEY)
            ->where('change_kind', 'proposal')
            ->where('policy_sha256', (string) $change->payload_sha256)
            ->orderByDesc('version_number')
            ->first();
        if ($proposal === null) {
            throw new GovernanceViolation('proposal_missing', 'No immutable AI provider policy proposal matches the approved change.');
        }
        if (! hash_equals((string) $proposal->policy_sha256, $this->hash($proposal->policy))) {
            throw new GovernanceViolation('proposal_hash_mismatch', 'The stored AI provider policy proposal no longer matches its recorded hash.');
        }

        return $proposal;
    }

    /** @param array<string, mixed> $policy */
    private function insertVersion(
        array $policy,
        string $changeKind,
        string $reason,
        ?int $actorUserId,
        ?string $governedChangeRequestUuid = null,
        ?int $rolledBackToVersionId = null,
    ): AiProviderPolicyVersion {
        $policy = $this->canonicalize($policy);
        $previous = $this->effectiveVersion();
        $nextNumber = (int) AiProviderPolicyVersion::query()
            ->where('policy_key', self::POLICY_KEY)
            ->max('version_number') + 1;

        return AiProviderPolicyVersion::query()->create([
            'policy_key' => self::POLICY_KEY,
            'version_number' => $nextNumber,
            'previous_version_id' => $previous?->getKey(),
            'rolled_back_to_version_id' => $rolledBackToVersionId,
            'policy' => $policy,
            'policy_sha256' => $this->hash($policy),
            'change_kind' => $changeKind,
            'change_reason' => $reason,
            'effective_at' => now(),
            'created_by_user_id' => $actorUserId,
            'governed_change_request_uuid' => $governedChangeRequestUuid,
            'created_at' => now(),
        ]);
    }

    /**
     * Project the applied document onto the eddy.* rows. Upserts every
     * declared profile/surface; profiles missing from the governed document
     * are disabled rather than deleted (non-destructive by default).
     *
     * @param  array<string, mixed>  $document
     */
    private function project(array $document, ?int $actorUserId): void
    {
        $declaredProfileIds = [];
        foreach ($document['profiles'] ?? [] as $profile) {
            $declaredProfileIds[] = (string) $profile['profile_id'];
            EddyProviderProfile::query()->updateOrCreate(
                ['profile_id' => (string) $profile['profile_id']],
                [
                    'display_name' => (string) $profile['display_name'],
                    'provider_type' => (string) $profile['provider_type'],
                    'transport' => (string) $profile['transport'],
                    'entitlement_type' => (string) $profile['entitlement_type'],
                    'model' => (string) $profile['model'],
                    'base_url' => $profile['base_url'],
                    'region' => $profile['region'],
                    'is_enabled' => (bool) $profile['is_enabled'],
                    'capabilities' => array_values($profile['capabilities']),
                    'safety' => ['patient_level_context_allowed' => (bool) $profile['safety']['patient_level_context_allowed']],
                    'limits' => array_filter($profile['limits'], fn (mixed $value): bool => $value !== null),
                    'fallback_profile_ids' => array_values($profile['fallback_profile_ids']),
                    'updated_by' => $actorUserId,
                ],
            );
        }

        if ($declaredProfileIds !== []) {
            EddyProviderProfile::query()
                ->whereNotIn('profile_id', $declaredProfileIds)
                ->where('is_enabled', true)
                ->get()
                ->each(function (EddyProviderProfile $profile) use ($actorUserId): void {
                    $profile->fill(['is_enabled' => false, 'updated_by' => $actorUserId])->save();
                });
        }

        foreach ($document['surfaces'] ?? [] as $surface) {
            EddySurfacePolicy::query()->updateOrCreate(
                ['surface' => (string) $surface['surface']],
                [
                    'provider_mode' => (string) $surface['provider_mode'],
                    'default_profile_id' => $surface['default_profile_id'],
                    'fallback_profile_ids' => array_values($surface['fallback_profile_ids']),
                    'allow_cloud' => (bool) $surface['allow_cloud'],
                    'never_send_phi_to_cloud' => (bool) $surface['never_send_phi_to_cloud'],
                    'required_capabilities' => array_values($surface['required_capabilities']),
                    'updated_by' => $actorUserId,
                ],
            );
        }
    }

    private function versionByNumber(int $versionNumber): AiProviderPolicyVersion
    {
        return AiProviderPolicyVersion::query()
            ->where('policy_key', self::POLICY_KEY)
            ->where('version_number', $versionNumber)
            ->where('change_kind', '!=', 'proposal')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $proposed
     * @return list<string>
     */
    private function changedSections(array $current, array $proposed): array
    {
        $sections = [];
        foreach (['profiles', 'surfaces'] as $section) {
            if (($current[$section] ?? []) !== ($proposed[$section] ?? [])) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            fn (mixed $nested): mixed => is_array($nested) ? $this->canonicalize($nested) : $nested,
            $value,
        );
    }
}
