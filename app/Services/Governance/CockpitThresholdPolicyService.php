<?php

namespace App\Services\Governance;

use App\Authorization\GovernedAction;
use App\Models\Governance\CockpitThresholdPolicyVersion;
use App\Models\Governance\GovernedChangeRequest;
use App\Models\Ops\MetricDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Versioned, dual-controlled cockpit threshold policy. Mirrors the
 * SourceConfigurationVersionService + GovernedChangeService contract:
 * a proposal is an immutable version row; application requires an
 * independently approved governed change bound to the exact policy hash;
 * rollback is a NEW version referencing a prior one. The effective policy
 * (ops.metric_definitions band edges) is only ever a projection.
 */
final class CockpitThresholdPolicyService
{
    /** @var list<string> */
    public const MUTABLE_FIELDS = [
        'owner',
        'unit',
        'direction',
        'target',
        'ok_edge',
        'warn_edge',
        'crit_edge',
        'refresh_secs',
        'alert_template',
        'is_active',
    ];

    public function __construct(private readonly GovernedChangeService $governance) {}

    /** @return array<string, mixed> */
    public function policyFromDefinition(MetricDefinition $definition): array
    {
        return $this->canonicalize([
            'metric_key' => (string) $definition->metric_key,
            'owner' => $definition->owner,
            'scope' => $definition->facility_key ?? 'house',
            'unit' => $definition->unit,
            'direction' => (string) ($definition->direction ?? 'neutral'),
            'target' => $definition->target_value !== null ? (float) $definition->target_value : null,
            'ok_edge' => $definition->ok_edge !== null ? (float) $definition->ok_edge : null,
            'warn_edge' => $definition->warn_edge !== null ? (float) $definition->warn_edge : null,
            'crit_edge' => $definition->crit_edge !== null ? (float) $definition->crit_edge : null,
            'refresh_secs' => (int) ($definition->refresh_secs ?? 300),
            'alert_template' => $definition->alert_template,
            'is_active' => (bool) ($definition->is_active ?? true),
        ]);
    }

    /**
     * Validation constraints for a proposed policy. Direction-aware monotonic
     * band edges, bounded refresh cadence, and a named owner are required.
     *
     * @param  array<string, mixed>  $policy
     * @return list<string>
     */
    public function validatePolicy(array $policy): array
    {
        $errors = [];
        $direction = (string) ($policy['direction'] ?? 'neutral');
        if (! in_array($direction, ['up', 'down', 'neutral'], true)) {
            $errors[] = 'direction_unsupported';
        }

        $owner = trim((string) ($policy['owner'] ?? ''));
        if ($owner === '' || mb_strlen($owner) > 160) {
            $errors[] = 'owner_required';
        }

        $unit = $policy['unit'] ?? null;
        if ($unit !== null && (! is_string($unit) || mb_strlen($unit) > 40)) {
            $errors[] = 'unit_invalid';
        }

        $refresh = $policy['refresh_secs'] ?? null;
        if (! is_int($refresh) || $refresh < 30 || $refresh > 86400) {
            $errors[] = 'refresh_secs_out_of_bounds';
        }

        $template = $policy['alert_template'] ?? null;
        if ($template !== null && (! is_string($template) || mb_strlen($template) > 500)) {
            $errors[] = 'alert_template_too_long';
        }

        foreach (['target', 'ok_edge', 'warn_edge', 'crit_edge'] as $edge) {
            if (($policy[$edge] ?? null) !== null && ! is_float($policy[$edge]) && ! is_int($policy[$edge])) {
                $errors[] = $edge.'_not_numeric';
            }
        }

        // StatusEngine semantics: direction 'down' breaches at/above an edge
        // (ok <= warn <= crit); direction 'up' breaches at/below (ok >= warn >= crit).
        if (in_array($direction, ['up', 'down'], true)) {
            $ordered = array_values(array_filter([
                $policy['ok_edge'] ?? null,
                $policy['warn_edge'] ?? null,
                $policy['crit_edge'] ?? null,
            ], fn (mixed $edge): bool => $edge !== null));
            for ($i = 1; $i < count($ordered); $i++) {
                $monotonic = $direction === 'down'
                    ? (float) $ordered[$i - 1] <= (float) $ordered[$i]
                    : (float) $ordered[$i - 1] >= (float) $ordered[$i];
                if (! $monotonic) {
                    $errors[] = 'edges_not_monotonic_for_direction';
                    break;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Compute the would-be policy for updates or a rollback target without
     * writing anything. The hash is the exact governed payload identity.
     *
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    public function preview(string $metricKey, array $updates, ?int $rollbackToVersionNumber = null): array
    {
        $definition = $this->definition($metricKey);
        $current = $this->policyFromDefinition($definition);
        $rollbackTarget = null;

        if ($rollbackToVersionNumber !== null) {
            $rollbackTarget = $this->versionByNumber($definition, $rollbackToVersionNumber);
            $proposed = $this->canonicalize(array_merge($rollbackTarget->policy, [
                'metric_key' => (string) $definition->metric_key,
                'scope' => $definition->facility_key ?? 'house',
            ]));
        } else {
            $proposed = $this->mergePolicy($current, $updates);
        }

        return [
            'metricKey' => (string) $definition->metric_key,
            'current' => $current,
            'proposed' => $proposed,
            'changedFields' => $this->changedFields($current, $proposed),
            'errors' => $this->validatePolicy($proposed),
            'policySha256' => $this->hash($proposed),
            'rollbackToVersionNumber' => $rollbackToVersionNumber,
        ];
    }

    /**
     * Create an immutable proposal version plus its governed change request.
     * The effective policy is untouched until independent approval + apply.
     *
     * @param  array<string, mixed>  $updates
     * @return array{version: CockpitThresholdPolicyVersion, change: GovernedChangeRequest}
     */
    public function requestChange(
        Request $request,
        string $metricKey,
        array $updates,
        string $reason,
        ?int $rollbackToVersionNumber = null,
    ): array {
        $preview = $this->preview($metricKey, $updates, $rollbackToVersionNumber);
        if ($preview['errors'] !== []) {
            throw ValidationException::withMessages(['policy' => $preview['errors']]);
        }
        if ($preview['changedFields'] === []) {
            throw ValidationException::withMessages([
                'policy' => ['The proposed threshold policy is identical to the effective version.'],
            ]);
        }

        return DB::transaction(function () use ($request, $metricKey, $reason, $preview): array {
            $definition = $this->definition($metricKey, lock: true);
            $rollbackTargetId = $preview['rollbackToVersionNumber'] !== null
                ? $this->versionByNumber($definition, (int) $preview['rollbackToVersionNumber'])->getKey()
                : null;

            $version = $this->insertVersion(
                $definition,
                $preview['proposed'],
                'proposal',
                $reason,
                $request->user()?->getKey(),
                rolledBackToVersionId: $rollbackTargetId,
            );

            $change = $this->governance->requestChange(
                $request,
                GovernedAction::ApplyCockpitThresholdPolicy,
                'cockpit_metric',
                (string) $definition->metric_key,
                $reason,
                (string) $version->policy_sha256,
                metadata: [
                    'metric_key' => (string) $definition->metric_key,
                    'policy_version' => (int) $version->version_number,
                    'rolled_back_to_version' => $preview['rollbackToVersionNumber'],
                    'changed_fields' => $preview['changedFields'],
                ],
            );

            return ['version' => $version, 'change' => $change];
        });
    }

    /**
     * Apply an approved proposal: append the effective version row and project
     * it onto ops.metric_definitions. Execution is bound to the exact proposal
     * hash the approver decided on; the author/approver split, step-up, and
     * expiry all enforce inside GovernedChangeService.
     */
    public function applyApproved(Request $request, string $changeRequestUuid): CockpitThresholdPolicyVersion
    {
        $change = GovernedChangeRequest::query()->whereKey($changeRequestUuid)->firstOrFail();
        $metricKey = (string) $change->subject_id;
        $proposal = $this->proposalForChange($change);

        return $this->governance->executeApproved(
            $request,
            $changeRequestUuid,
            GovernedAction::ApplyCockpitThresholdPolicy,
            'cockpit_metric',
            $metricKey,
            (string) $proposal->policy_sha256,
            function () use ($request, $metricKey, $proposal, $change): CockpitThresholdPolicyVersion {
                $definition = $this->definition($metricKey, lock: true);
                $errors = $this->validatePolicy($proposal->policy);
                if ($errors !== []) {
                    throw ValidationException::withMessages(['policy' => $errors]);
                }

                $applied = $this->insertVersion(
                    $definition,
                    $proposal->policy,
                    $proposal->rolled_back_to_version_id !== null ? 'rollback' : 'governed_application',
                    (string) $change->reason,
                    $request->user()?->getKey(),
                    governedChangeRequestUuid: (string) $change->getKey(),
                    rolledBackToVersionId: $proposal->rolled_back_to_version_id,
                );
                $this->project($definition, $applied->policy);

                return $applied;
            },
        );
    }

    /**
     * Additive wrap for the legacy band-edge editor endpoint: the direct tune
     * stays audited and functional, but the ledger never misses an effective
     * change — the post-change policy is appended as a direct_update version.
     */
    public function recordDirectUpdate(MetricDefinition $definition, ?int $actorUserId): CockpitThresholdPolicyVersion
    {
        return $this->insertVersion(
            $definition->refresh(),
            $this->policyFromDefinition($definition),
            'direct_update',
            'Band-edge tune through the audited legacy cockpit threshold editor endpoint.',
            $actorUserId,
        );
    }

    /**
     * The effective (latest non-proposal) version for a metric, if any.
     */
    public function effectiveVersion(MetricDefinition $definition): ?CockpitThresholdPolicyVersion
    {
        return CockpitThresholdPolicyVersion::query()
            ->where('metric_definition_id', $definition->metric_definition_id)
            ->where('change_kind', '!=', 'proposal')
            ->orderByDesc('version_number')
            ->first();
    }

    /** @return list<array<string, mixed>> */
    public function history(string $metricKey, int $limit = 50): array
    {
        $definition = $this->definition($metricKey);

        return CockpitThresholdPolicyVersion::query()
            ->with('author:id,name,username')
            ->where('metric_definition_id', $definition->metric_definition_id)
            ->orderByDesc('version_number')
            ->limit($limit)
            ->get()
            ->map(fn (CockpitThresholdPolicyVersion $version): array => $this->versionPayload($version))
            ->all();
    }

    /**
     * Duplicate/ambiguous metric key detection. A duplicate is a normalized
     * full-key collision (case/separator variants of the same key); an
     * ambiguous key is the same base name registered under more than one
     * domain or scope, which makes an unqualified reference unresolvable.
     *
     * @return list<array<string, mixed>>
     */
    public function duplicateKeyReport(): array
    {
        $definitions = MetricDefinition::query()
            ->orderBy('metric_key')
            ->get(['metric_definition_id', 'metric_key', 'domain', 'facility_key', 'status', 'is_active']);

        $member = fn (MetricDefinition $definition): array => [
            'metricKey' => (string) $definition->metric_key,
            'domain' => (string) $definition->domain,
            'scope' => $definition->facility_key ?? 'house',
            'active' => (bool) ($definition->is_active ?? true),
        ];

        $report = [];

        foreach ($definitions->groupBy(fn (MetricDefinition $definition): string => $this->normalizeKey($definition->metric_key)) as $normalized => $group) {
            if ($group->count() > 1) {
                $report[] = [
                    'normalizedKey' => (string) $normalized,
                    'kind' => 'duplicate',
                    'members' => $group->map($member)->values()->all(),
                ];
            }
        }

        foreach ($definitions->groupBy(fn (MetricDefinition $definition): string => $this->normalizeKey(Str::contains($definition->metric_key, '.') ? Str::after($definition->metric_key, '.') : $definition->metric_key)) as $base => $group) {
            $distinctContexts = $group
                ->map(fn (MetricDefinition $definition): string => $definition->domain.'|'.($definition->facility_key ?? 'house'))
                ->unique();
            $alreadyDuplicate = $group->map(fn (MetricDefinition $definition): string => $this->normalizeKey($definition->metric_key))->unique()->count() === 1
                && $group->count() > 1;
            if ($group->count() > 1 && $distinctContexts->count() > 1 && ! $alreadyDuplicate) {
                $report[] = [
                    'normalizedKey' => (string) $base,
                    'kind' => 'ambiguous',
                    'members' => $group->map($member)->values()->all(),
                ];
            }
        }

        return $report;
    }

    /** @param array<string, mixed> $payload */
    public function hash(array $payload): string
    {
        return $this->governance->hashPayload($payload);
    }

    /** @return array<string, mixed> */
    public function versionPayload(CockpitThresholdPolicyVersion $version): array
    {
        return [
            'versionId' => (int) $version->getKey(),
            'versionNumber' => (int) $version->version_number,
            'changeKind' => (string) $version->change_kind,
            'changeReason' => (string) $version->change_reason,
            'policy' => $version->policy,
            'policySha256' => (string) $version->policy_sha256,
            'previousVersionId' => $version->previous_version_id,
            'rolledBackToVersionId' => $version->rolled_back_to_version_id,
            'effectiveAtIso' => $version->effective_at?->toIso8601String(),
            'createdAtIso' => $version->created_at?->toIso8601String(),
            'createdBy' => $version->author?->only(['id', 'name', 'username']),
            'governedChangeRequestUuid' => $version->governed_change_request_uuid,
        ];
    }

    private function proposalForChange(GovernedChangeRequest $change): CockpitThresholdPolicyVersion
    {
        $proposal = CockpitThresholdPolicyVersion::query()
            ->where('metric_key', (string) $change->subject_id)
            ->where('change_kind', 'proposal')
            ->where('policy_sha256', (string) $change->payload_sha256)
            ->orderByDesc('version_number')
            ->first();
        if ($proposal === null) {
            throw new GovernanceViolation('proposal_missing', 'No immutable threshold policy proposal matches the approved change.');
        }
        if (! hash_equals((string) $proposal->policy_sha256, $this->hash($proposal->policy))) {
            throw new GovernanceViolation('proposal_hash_mismatch', 'The stored threshold policy proposal no longer matches its recorded hash.');
        }

        return $proposal;
    }

    /** @param array<string, mixed> $policy */
    private function insertVersion(
        MetricDefinition $definition,
        array $policy,
        string $changeKind,
        string $reason,
        ?int $actorUserId,
        ?string $governedChangeRequestUuid = null,
        ?int $rolledBackToVersionId = null,
    ): CockpitThresholdPolicyVersion {
        $policy = $this->canonicalize($policy);
        $previous = $this->effectiveVersion($definition);
        $nextNumber = (int) CockpitThresholdPolicyVersion::query()
            ->where('metric_definition_id', $definition->metric_definition_id)
            ->max('version_number') + 1;

        return CockpitThresholdPolicyVersion::query()->create([
            'metric_definition_id' => $definition->metric_definition_id,
            'metric_key' => (string) $definition->metric_key,
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

    /** @param array<string, mixed> $policy */
    private function project(MetricDefinition $definition, array $policy): void
    {
        $definition->fill([
            'owner' => $policy['owner'] ?? null,
            'unit' => $policy['unit'] ?? null,
            'direction' => $policy['direction'] ?? 'neutral',
            'target_value' => $policy['target'] ?? null,
            'ok_edge' => $policy['ok_edge'] ?? null,
            'warn_edge' => $policy['warn_edge'] ?? null,
            'crit_edge' => $policy['crit_edge'] ?? null,
            'refresh_secs' => $policy['refresh_secs'] ?? 300,
            'alert_template' => $policy['alert_template'] ?? null,
            'is_active' => (bool) ($policy['is_active'] ?? true),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergePolicy(array $current, array $updates): array
    {
        $proposed = $current;
        foreach (self::MUTABLE_FIELDS as $field) {
            if (! array_key_exists($field, $updates)) {
                continue;
            }
            $value = $updates[$field];
            $proposed[$field] = match ($field) {
                'is_active' => (bool) $value,
                'refresh_secs' => $value === null ? null : (int) $value,
                'target', 'ok_edge', 'warn_edge', 'crit_edge' => $value === null ? null : (float) $value,
                default => $value === null || $value === '' ? null : (string) $value,
            };
        }

        return $this->canonicalize($proposed);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changedFields(array $before, array $after): array
    {
        $fields = [];
        foreach (self::MUTABLE_FIELDS as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function definition(string $metricKey, bool $lock = false): MetricDefinition
    {
        $query = MetricDefinition::query()->where('metric_key', $metricKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function versionByNumber(MetricDefinition $definition, int $versionNumber): CockpitThresholdPolicyVersion
    {
        return CockpitThresholdPolicyVersion::query()
            ->where('metric_definition_id', $definition->metric_definition_id)
            ->where('version_number', $versionNumber)
            ->where('change_kind', '!=', 'proposal')
            ->firstOrFail();
    }

    private function normalizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($key)));
    }

    /** @param array<string, mixed> $policy @return array<string, mixed> */
    private function canonicalize(array $policy): array
    {
        ksort($policy);

        return $policy;
    }
}
