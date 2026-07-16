<?php

namespace App\Integrations\Healthcare\Services;

use App\Services\Governance\GovernanceViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SourceActivationWindowService
{
    public function __construct(
        private readonly SourceReadinessService $readiness,
        private readonly SourceLifecycleService $lifecycle,
    ) {}

    public function newUuid(): string
    {
        return (string) Str::uuid7();
    }

    /** @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    public function requestContract(
        int $sourceId,
        string $windowUuid,
        CarbonImmutable $activateAt,
        CarbonImmutable $windowEndsAt,
        string $requestedTimezone,
        array $assessment,
    ): array {
        $source = DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();

        return [
            'activation_window_uuid' => $windowUuid,
            'source_id' => $sourceId,
            'source_uuid' => (string) $source->source_uuid,
            'organization_id' => (int) $source->organization_id,
            'facility_id' => (int) $source->facility_id,
            'configuration_version_id' => (int) $assessment['configurationVersionId'],
            'configuration_sha256' => (string) $assessment['configurationSha256'],
            'onboarding_version_id' => (int) $assessment['onboardingVersionId'],
            'onboarding_profile_sha256' => (string) $assessment['onboardingProfileSha256'],
            'readiness_assessment_id' => (int) $assessment['readinessAssessmentId'],
            'readiness_input_sha256' => (string) $assessment['inputSha256'],
            'activate_at' => $activateAt->utc()->toIso8601String(),
            'window_ends_at' => $windowEndsAt->utc()->toIso8601String(),
            'requested_timezone' => $requestedTimezone,
            'desired_lifecycle_state' => 'live',
        ];
    }

    /** @param array<string, mixed> $assessment */
    public function createPending(
        int $sourceId,
        string $windowUuid,
        string $governedChangeUuid,
        CarbonImmutable $activateAt,
        CarbonImmutable $windowEndsAt,
        string $requestedTimezone,
        array $assessment,
        int $requestedByUserId,
        string $reason,
    ): object {
        $this->assertWindow($activateAt, $windowEndsAt);

        return DB::transaction(function () use (
            $sourceId,
            $windowUuid,
            $governedChangeUuid,
            $activateAt,
            $windowEndsAt,
            $requestedTimezone,
            $assessment,
            $requestedByUserId,
            $reason,
        ): object {
            DB::table('integration.sources')->where('source_id', $sourceId)->lockForUpdate()->firstOrFail();
            if (DB::table('integration.source_activation_windows')
                ->where('source_id', $sourceId)
                ->whereIn('status', ['pending_approval', 'scheduled', 'leased'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'activation_window' => 'The source already has an open activation window.',
                ]);
            }

            $windowId = (int) DB::table('integration.source_activation_windows')->insertGetId([
                'activation_window_uuid' => $windowUuid,
                'source_id' => $sourceId,
                'configuration_version_id' => $assessment['configurationVersionId'],
                'onboarding_version_id' => $assessment['onboardingVersionId'],
                'readiness_assessment_id' => $assessment['readinessAssessmentId'],
                'governed_change_request_uuid' => $governedChangeUuid,
                'status' => 'pending_approval',
                'activate_at' => $activateAt,
                'window_ends_at' => $windowEndsAt,
                'requested_timezone' => $requestedTimezone,
                'attempt_count' => 0,
                'max_attempts' => 3,
                'reason' => $this->reason($reason),
                'requested_by_user_id' => $requestedByUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'source_activation_window_id');

            return $this->windowById($windowId);
        });
    }

    /** @return array<string, mixed> */
    public function contract(object $window): array
    {
        $row = $this->authorityRow((int) $window->source_activation_window_id);

        return [
            'activation_window_uuid' => (string) $row->activation_window_uuid,
            'source_id' => (int) $row->source_id,
            'source_uuid' => (string) $row->source_uuid,
            'organization_id' => (int) $row->organization_id,
            'facility_id' => (int) $row->facility_id,
            'configuration_version_id' => (int) $row->configuration_version_id,
            'configuration_sha256' => (string) $row->configuration_sha256,
            'onboarding_version_id' => (int) $row->onboarding_version_id,
            'onboarding_profile_sha256' => (string) $row->profile_sha256,
            'readiness_assessment_id' => (int) $row->readiness_assessment_id,
            'readiness_input_sha256' => (string) $row->input_sha256,
            'activate_at' => CarbonImmutable::parse((string) $row->activate_at)->utc()->toIso8601String(),
            'window_ends_at' => CarbonImmutable::parse((string) $row->window_ends_at)->utc()->toIso8601String(),
            'requested_timezone' => (string) $row->requested_timezone,
            'desired_lifecycle_state' => 'live',
        ];
    }

    public function windowForChange(string $changeRequestUuid, bool $lock = false): object
    {
        $query = DB::table('integration.source_activation_windows')
            ->where('governed_change_request_uuid', $changeRequestUuid);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function schedule(
        string $changeRequestUuid,
        int $scheduledByUserId,
    ): array {
        return DB::transaction(function () use ($changeRequestUuid, $scheduledByUserId): array {
            $window = $this->windowForChange($changeRequestUuid, lock: true);
            if ((string) $window->status !== 'pending_approval') {
                throw new GovernanceViolation('activation_window_not_pending', 'The activation window is no longer pending approval execution.');
            }
            if (CarbonImmutable::parse((string) $window->window_ends_at)->isPast()) {
                throw new GovernanceViolation('activation_window_expired', 'The approved activation window has already expired.');
            }
            $this->assertAuthorityStillMatches($window);

            $this->lifecycle->transitionToScheduled(
                (int) $window->source_id,
                (string) $window->reason,
                $scheduledByUserId,
                $changeRequestUuid,
                ['activation_window_uuid' => (string) $window->activation_window_uuid],
            );
            DB::table('integration.source_activation_windows')
                ->where('source_activation_window_id', $window->source_activation_window_id)
                ->update([
                    'status' => 'scheduled',
                    'scheduled_by_user_id' => $scheduledByUserId,
                    'scheduled_at' => now(),
                    'updated_at' => now(),
                ]);

            return $this->payload($this->windowById((int) $window->source_activation_window_id));
        });
    }

    /** @return list<array<string, mixed>> */
    public function windows(int $sourceId): array
    {
        DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();

        return DB::table('integration.source_activation_windows')
            ->where('source_id', $sourceId)
            ->orderByDesc('source_activation_window_id')
            ->limit(25)
            ->get()
            ->map(fn (object $row): array => $this->payload($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public function cancel(
        int $sourceId,
        string $windowUuid,
        int $actorUserId,
        string $reason,
    ): array {
        return DB::transaction(function () use ($sourceId, $windowUuid, $actorUserId, $reason): array {
            $window = DB::table('integration.source_activation_windows')
                ->where('activation_window_uuid', $windowUuid)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array((string) $window->status, ['pending_approval', 'scheduled', 'leased'], true)) {
                throw new GovernanceViolation('activation_window_terminal', 'The activation window is already terminal.');
            }
            $reason = $this->reason($reason);
            DB::table('integration.source_activation_windows')
                ->where('source_activation_window_id', $window->source_activation_window_id)
                ->update([
                    'status' => 'cancelled',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => $actorUserId,
                    'cancellation_reason' => $reason,
                    'updated_at' => now(),
                ]);
            $this->releaseScheduledLifecycle(
                $window,
                'The approved activation window was cancelled before execution.',
                'activation_window_cancelled',
            );

            return $this->payload($this->windowById((int) $window->source_activation_window_id));
        });
    }

    /** @return array{expired: int, claimed: int, activated: int, failed: int} */
    public function runDue(string $leaseOwner, int $limit = 25, int $leaseSeconds = 120): array
    {
        $leaseOwner = $this->leaseOwner($leaseOwner);
        $limit = max(1, min(100, $limit));
        $leaseSeconds = max(30, min(900, $leaseSeconds));
        $expired = $this->expireMissed();
        $failed = $this->failExhaustedLeases();
        $claimed = $this->claimDue($leaseOwner, $limit, $leaseSeconds);
        $activated = 0;

        foreach ($claimed as $window) {
            try {
                $this->executeClaimed((string) $window->activation_window_uuid, $leaseOwner);
                $activated++;
            } catch (Throwable $exception) {
                $this->failClaim(
                    (string) $window->activation_window_uuid,
                    $leaseOwner,
                    $exception instanceof GovernanceViolation ? $exception->reason : 'activation_precondition_failed',
                );
                $failed++;
            }
        }

        return [
            'expired' => $expired,
            'claimed' => count($claimed),
            'activated' => $activated,
            'failed' => $failed,
        ];
    }

    /** @return list<object> */
    public function claimDue(string $leaseOwner, int $limit, int $leaseSeconds): array
    {
        return DB::transaction(function () use ($leaseOwner, $limit, $leaseSeconds): array {
            $now = CarbonImmutable::now();
            $rows = DB::table('integration.source_activation_windows')
                ->where(function (Builder $query) use ($now): void {
                    $query->where('status', 'scheduled')
                        ->orWhere(function (Builder $leased) use ($now): void {
                            $leased->where('status', 'leased')->where('lease_expires_at', '<=', $now);
                        });
                })
                ->where('activate_at', '<=', $now)
                ->where('window_ends_at', '>', $now)
                ->whereColumn('attempt_count', '<', 'max_attempts')
                ->orderBy('activate_at')
                ->orderBy('source_activation_window_id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            foreach ($rows as $row) {
                DB::table('integration.source_activation_windows')
                    ->where('source_activation_window_id', $row->source_activation_window_id)
                    ->update([
                        'status' => 'leased',
                        'lease_owner' => $leaseOwner,
                        'lease_expires_at' => $now->addSeconds($leaseSeconds),
                        'attempt_count' => (int) $row->attempt_count + 1,
                        'updated_at' => $now,
                    ]);
                $row->status = 'leased';
                $row->lease_owner = $leaseOwner;
                $row->lease_expires_at = $now->addSeconds($leaseSeconds);
                $row->attempt_count = (int) $row->attempt_count + 1;
            }

            return $rows->all();
        });
    }

    /** @return array<string, mixed> */
    public function executeClaimed(string $windowUuid, string $leaseOwner): array
    {
        return DB::transaction(function () use ($windowUuid, $leaseOwner): array {
            $window = DB::table('integration.source_activation_windows')
                ->where('activation_window_uuid', $windowUuid)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $window->status !== 'leased'
                || ! hash_equals((string) $window->lease_owner, $leaseOwner)
                || CarbonImmutable::parse((string) $window->lease_expires_at)->isPast()) {
                throw new GovernanceViolation('activation_lease_invalid', 'The scheduler no longer owns a valid activation lease.');
            }
            $now = CarbonImmutable::now();
            if ($now->greaterThanOrEqualTo(CarbonImmutable::parse((string) $window->window_ends_at))) {
                throw new GovernanceViolation('activation_window_expired', 'The activation window closed before execution.');
            }
            $this->assertAuthorityStillMatches($window);
            $sourceState = (string) DB::table('integration.sources')
                ->where('source_id', $window->source_id)
                ->lockForUpdate()
                ->value('lifecycle_state');
            if ($sourceState !== 'scheduled') {
                throw new GovernanceViolation('source_lifecycle_drift', 'The source is no longer in its approved scheduled state.');
            }
            $this->readiness->requireReady((int) $window->source_id, $now, null);

            $this->lifecycle->transitionToLive(
                (int) $window->source_id,
                (string) $window->reason,
                null,
                (string) $window->governed_change_request_uuid,
            );
            DB::table('integration.source_activation_windows')
                ->where('source_activation_window_id', $window->source_activation_window_id)
                ->update([
                    'status' => 'activated',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'activated_at' => now(),
                    'updated_at' => now(),
                ]);

            return $this->payload($this->windowById((int) $window->source_activation_window_id));
        });
    }

    public function expireMissed(): int
    {
        $rows = DB::table('integration.source_activation_windows')
            ->whereIn('status', ['pending_approval', 'scheduled', 'leased'])
            ->where('window_ends_at', '<=', now())
            ->get(['source_activation_window_id']);
        $expired = 0;
        foreach ($rows as $row) {
            $didExpire = DB::transaction(function () use ($row): bool {
                $window = DB::table('integration.source_activation_windows')
                    ->where('source_activation_window_id', $row->source_activation_window_id)
                    ->whereIn('status', ['pending_approval', 'scheduled', 'leased'])
                    ->where('window_ends_at', '<=', now())
                    ->lockForUpdate()
                    ->first();
                if ($window === null) {
                    return false;
                }
                DB::table('integration.source_activation_windows')
                    ->where('source_activation_window_id', $window->source_activation_window_id)
                    ->update([
                        'status' => 'expired',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => 'activation_window_expired',
                        'last_error_summary' => 'The approved activation window closed before successful execution.',
                        'failed_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->releaseScheduledLifecycle(
                    $window,
                    'The approved activation window expired before execution.',
                    'activation_window_expired',
                );

                return true;
            });
            $expired += $didExpire ? 1 : 0;
        }

        return $expired;
    }

    private function failExhaustedLeases(): int
    {
        $rows = DB::table('integration.source_activation_windows')
            ->where('status', 'leased')
            ->where('lease_expires_at', '<=', now())
            ->whereColumn('attempt_count', '>=', 'max_attempts')
            ->where('window_ends_at', '>', now())
            ->get(['source_activation_window_id']);
        $failed = 0;
        foreach ($rows as $row) {
            $didFail = DB::transaction(function () use ($row): bool {
                $window = DB::table('integration.source_activation_windows')
                    ->where('source_activation_window_id', $row->source_activation_window_id)
                    ->where('status', 'leased')
                    ->where('lease_expires_at', '<=', now())
                    ->whereColumn('attempt_count', '>=', 'max_attempts')
                    ->where('window_ends_at', '>', now())
                    ->lockForUpdate()
                    ->first();
                if ($window === null) {
                    return false;
                }
                DB::table('integration.source_activation_windows')
                    ->where('source_activation_window_id', $window->source_activation_window_id)
                    ->update([
                        'status' => 'failed',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => 'activation_attempts_exhausted',
                        'last_error_summary' => 'Activation failed closed after all scheduler lease attempts were exhausted.',
                        'failed_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->releaseScheduledLifecycle(
                    $window,
                    'Scheduled activation failed closed after exhausting its execution attempts.',
                    'activation_attempts_exhausted',
                );

                return true;
            });
            $failed += $didFail ? 1 : 0;
        }

        return $failed;
    }

    private function assertAuthorityStillMatches(object $window): void
    {
        $source = DB::table('integration.sources')
            ->where('source_id', $window->source_id)
            ->firstOrFail();
        $latestOnboardingId = (int) DB::table('integration.source_onboarding_versions')
            ->where('source_id', $window->source_id)
            ->max('source_onboarding_version_id');
        if ((int) $source->current_configuration_version_id !== (int) $window->configuration_version_id
            || $latestOnboardingId !== (int) $window->onboarding_version_id) {
            throw new GovernanceViolation('approved_payload_mismatch', 'Configuration or onboarding authority changed after approval.');
        }
        $approvedAssessment = DB::table('integration.source_readiness_assessments')
            ->where('source_readiness_assessment_id', $window->readiness_assessment_id)
            ->firstOrFail();
        $fresh = $this->readiness->evaluate(
            (int) $window->source_id,
            CarbonImmutable::parse((string) $window->activate_at),
            null,
            persist: false,
        );
        if ($fresh['status'] !== 'ready'
            || ! hash_equals((string) $approvedAssessment->input_sha256, (string) $fresh['inputSha256'])) {
            throw new GovernanceViolation('approved_payload_mismatch', 'Readiness evidence changed after approval.');
        }
    }

    private function failClaim(string $windowUuid, string $leaseOwner, string $code): void
    {
        DB::transaction(function () use ($windowUuid, $leaseOwner, $code): void {
            $window = DB::table('integration.source_activation_windows')
                ->where('activation_window_uuid', $windowUuid)
                ->lockForUpdate()
                ->first();
            if ($window === null || (string) $window->status !== 'leased'
                || ! hash_equals((string) $window->lease_owner, $leaseOwner)) {
                return;
            }
            DB::table('integration.source_activation_windows')
                ->where('source_activation_window_id', $window->source_activation_window_id)
                ->update([
                    'status' => 'failed',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => $this->errorCode($code),
                    'last_error_summary' => 'Activation failed closed because approved execution preconditions no longer matched.',
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->releaseScheduledLifecycle(
                $window,
                'Scheduled activation failed closed after its authority changed.',
                $this->errorCode($code),
            );
        });
    }

    private function releaseScheduledLifecycle(object $window, string $reason, string $code): void
    {
        $state = DB::table('integration.sources')
            ->where('source_id', $window->source_id)
            ->value('lifecycle_state');
        if ($state !== 'scheduled') {
            return;
        }
        $this->lifecycle->transition(
            (int) $window->source_id,
            'approved',
            $reason,
            null,
            (string) $window->governed_change_request_uuid,
            [
                'activation_window_uuid' => (string) $window->activation_window_uuid,
                'activation_failure_code' => $code,
            ],
        );
    }

    private function authorityRow(int $windowId): object
    {
        return DB::table('integration.source_activation_windows as window')
            ->join('integration.sources as source', 'source.source_id', '=', 'window.source_id')
            ->join(
                'integration.source_configuration_versions as configuration',
                'configuration.source_configuration_version_id',
                '=',
                'window.configuration_version_id',
            )
            ->join(
                'integration.source_onboarding_versions as onboarding',
                'onboarding.source_onboarding_version_id',
                '=',
                'window.onboarding_version_id',
            )
            ->join(
                'integration.source_readiness_assessments as readiness',
                'readiness.source_readiness_assessment_id',
                '=',
                'window.readiness_assessment_id',
            )
            ->where('window.source_activation_window_id', $windowId)
            ->select([
                'window.*',
                'source.source_uuid',
                'source.organization_id',
                'source.facility_id',
                'configuration.configuration_sha256',
                'onboarding.profile_sha256',
                'readiness.input_sha256',
            ])->firstOrFail();
    }

    private function windowById(int $windowId): object
    {
        return DB::table('integration.source_activation_windows')
            ->where('source_activation_window_id', $windowId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function payload(object $row): array
    {
        return [
            'activationWindowId' => (int) $row->source_activation_window_id,
            'activationWindowUuid' => (string) $row->activation_window_uuid,
            'sourceId' => (int) $row->source_id,
            'configurationVersionId' => (int) $row->configuration_version_id,
            'onboardingVersionId' => (int) $row->onboarding_version_id,
            'readinessAssessmentId' => (int) $row->readiness_assessment_id,
            'governedChangeRequestUuid' => (string) $row->governed_change_request_uuid,
            'status' => (string) $row->status,
            'activateAtIso' => CarbonImmutable::parse((string) $row->activate_at)->toIso8601String(),
            'windowEndsAtIso' => CarbonImmutable::parse((string) $row->window_ends_at)->toIso8601String(),
            'requestedTimezone' => (string) $row->requested_timezone,
            'attemptCount' => (int) $row->attempt_count,
            'maxAttempts' => (int) $row->max_attempts,
            'lastErrorCode' => $row->last_error_code,
            'reason' => (string) $row->reason,
            'requestedByUserId' => (int) $row->requested_by_user_id,
            'scheduledByUserId' => $row->scheduled_by_user_id !== null ? (int) $row->scheduled_by_user_id : null,
            'scheduledAtIso' => $this->iso($row->scheduled_at),
            'activatedAtIso' => $this->iso($row->activated_at),
            'failedAtIso' => $this->iso($row->failed_at),
            'cancelledAtIso' => $this->iso($row->cancelled_at),
            'cancelledByUserId' => $row->cancelled_by_user_id !== null ? (int) $row->cancelled_by_user_id : null,
            'cancellationReason' => $row->cancellation_reason,
            'createdAtIso' => $this->iso($row->created_at),
        ];
    }

    private function assertWindow(CarbonImmutable $activateAt, CarbonImmutable $windowEndsAt): void
    {
        if ($activateAt->lessThanOrEqualTo(CarbonImmutable::now()->addMinute())) {
            throw ValidationException::withMessages([
                'activate_at' => 'Scheduled activation must be at least one minute in the future.',
            ]);
        }
        if ($windowEndsAt->lessThanOrEqualTo($activateAt)
            || $windowEndsAt->greaterThan($activateAt->addHours(24))) {
            throw ValidationException::withMessages([
                'window_ends_at' => 'The activation window must close after activation and within 24 hours.',
            ]);
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A 10-500 character reason is required.']);
        }

        return $reason;
    }

    private function leaseOwner(string $owner): string
    {
        $owner = trim($owner);
        if (preg_match('/^[A-Za-z0-9_.:@-]{1,190}$/', $owner) !== 1) {
            throw new \InvalidArgumentException('The scheduler lease owner is invalid.');
        }

        return $owner;
    }

    private function errorCode(string $code): string
    {
        $code = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $code) ?? 'activation_failed');

        return substr(trim($code, '_'), 0, 80) ?: 'activation_failed';
    }

    private function iso(mixed $value): ?string
    {
        return $value !== null ? CarbonImmutable::parse((string) $value)->toIso8601String() : null;
    }
}
