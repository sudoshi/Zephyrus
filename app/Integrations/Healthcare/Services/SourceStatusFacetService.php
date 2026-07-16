<?php

namespace App\Integrations\Healthcare\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * INT-LIFECYCLE — the six status facets made independently addressable.
 *
 * `integration.sources` used to conflate everything into one status. This
 * service reads and (for the three new facets) governs the SIX distinct
 * facets so they never collapse into a single signal:
 *
 *   1. lifecycle_state   — governed transition authority (SourceLifecycleService)
 *   2. protocol_health   — read-only runtime evidence (SourceObservabilityService)
 *   3. data_freshness    — read-only derived signal (SourceHealthDigestService)
 *   4. conformance_status— governed, append-only (source_conformance_events)
 *   5. contract_status   — governed, append-only, evidence-pointer only
 *   6. incident_status   — operator-updatable, append-only, breach-linkable
 *
 * Reads never call a partner or advance a cursor; every mutation appends an
 * immutable event and advances the trigger-guarded current projection.
 */
final class SourceStatusFacetService
{
    /** @var list<string> */
    public const CONFORMANCE_STATUSES = ['not_started', 'in_progress', 'passed', 'failed', 'waived'];

    /** @var list<string> */
    public const CONTRACT_STATUSES = ['none', 'pending', 'active', 'expired'];

    /** @var list<string> */
    public const INCIDENT_STATUSES = ['none', 'open', 'monitoring', 'resolved'];

    public function __construct(
        private readonly SourceHealthDigestService $digest,
    ) {}

    /**
     * A bounded, PHI-free snapshot of all six facets for one source. Each facet
     * is a distinct block; none is derived from another.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $sourceId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $source = DB::table('integration.sources')->where('source_id', $sourceId)->firstOrFail();
        $facets = $this->currentFacets($sourceId);
        $health = DB::table('integration.source_health_current')->where('source_id', $sourceId)->first();
        $digest = collect($this->digest->digest($now)['sources'])
            ->firstWhere('sourceKey', $source->source_key);

        $protocolStatus = $health?->observation_status !== null ? (string) $health->observation_status : 'unobserved';
        $freshExpiresAt = $health?->freshness_expires_at !== null
            ? CarbonImmutable::parse((string) $health->freshness_expires_at)
            : null;
        $stale = $freshExpiresAt === null || $freshExpiresAt->lessThanOrEqualTo($now);
        $contractExpiresAt = $facets->contract_expires_at !== null
            ? CarbonImmutable::parse((string) $facets->contract_expires_at)
            : null;

        return [
            'sourceId' => $sourceId,
            'lifecycle' => [
                'state' => (string) $source->lifecycle_state,
                'changedAtIso' => $source->lifecycle_changed_at !== null
                    ? CarbonImmutable::parse((string) $source->lifecycle_changed_at)->toIso8601String()
                    : null,
                'governed' => true,
            ],
            'protocolHealth' => [
                'status' => $protocolStatus,
                'observedAtIso' => $health?->observed_at !== null
                    ? CarbonImmutable::parse((string) $health->observed_at)->toIso8601String()
                    : null,
                'readOnly' => true,
            ],
            'dataFreshness' => [
                'stale' => $stale,
                'freshUntilIso' => $freshExpiresAt?->toIso8601String(),
                'digestStatus' => is_array($digest) ? (string) $digest['status'] : 'unobserved',
                'readOnly' => true,
            ],
            'conformance' => [
                'status' => (string) $facets->conformance_status,
                'profileKey' => $facets->conformance_profile_key,
                'profileVersion' => $facets->conformance_profile_version,
                'governed' => true,
            ],
            'contract' => [
                'status' => (string) $facets->contract_status,
                'expiresAtIso' => $contractExpiresAt?->toIso8601String(),
                'expired' => $contractExpiresAt !== null && $contractExpiresAt->lessThanOrEqualTo($now),
                'governed' => true,
            ],
            'incident' => [
                'status' => (string) $facets->incident_status,
                'operatorUpdatable' => true,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function conformanceHistory(int $sourceId): array
    {
        return $this->history('source_conformance_events', 'source_conformance_event_id', $sourceId, fn (object $row): array => [
            'eventUuid' => (string) $row->event_uuid,
            'status' => (string) $row->conformance_status,
            'profileKey' => $row->profile_key,
            'profileVersion' => $row->profile_version,
            'reason' => (string) $row->reason,
            'actorUserId' => $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
            'occurredAtIso' => CarbonImmutable::parse((string) $row->occurred_at)->toIso8601String(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function contractHistory(int $sourceId): array
    {
        return $this->history('source_contract_events', 'source_contract_event_id', $sourceId, fn (object $row): array => [
            'eventUuid' => (string) $row->event_uuid,
            'status' => (string) $row->contract_status,
            'evidenceRecordId' => $row->source_evidence_record_id !== null ? (int) $row->source_evidence_record_id : null,
            'expiresAtIso' => $row->expires_at !== null ? CarbonImmutable::parse((string) $row->expires_at)->toIso8601String() : null,
            'reason' => (string) $row->reason,
            'actorUserId' => $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
            'occurredAtIso' => CarbonImmutable::parse((string) $row->occurred_at)->toIso8601String(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function incidentHistory(int $sourceId): array
    {
        return $this->history('source_incident_events', 'source_incident_event_id', $sourceId, fn (object $row): array => [
            'eventUuid' => (string) $row->event_uuid,
            'status' => (string) $row->incident_status,
            'incidentReferenceHash' => $row->incident_reference_hash,
            'sloBreachId' => $row->slo_breach_id !== null ? (int) $row->slo_breach_id : null,
            'reason' => (string) $row->reason,
            'actorUserId' => $row->actor_user_id !== null ? (int) $row->actor_user_id : null,
            'occurredAtIso' => CarbonImmutable::parse((string) $row->occurred_at)->toIso8601String(),
        ]);
    }

    /**
     * Record a governed conformance transition. Append-only: a new event plus a
     * projection advance, never a mutation of history.
     *
     * @return array<string, mixed>
     */
    public function recordConformance(
        int $sourceId,
        string $status,
        ?string $profileKey,
        ?string $profileVersion,
        string $reason,
        ?int $actorUserId,
    ): array {
        if (! in_array($status, self::CONFORMANCE_STATUSES, true)) {
            throw ValidationException::withMessages(['conformance_status' => 'The conformance status is not supported.']);
        }
        $reason = $this->reason($reason);
        $profileKey = $this->normalizeOptional($profileKey);
        $profileVersion = $this->normalizeOptional($profileVersion);
        if (in_array($status, ['passed', 'waived'], true) && $profileKey === null) {
            throw ValidationException::withMessages(['profile_key' => 'A passed or waived conformance state requires a named profile.']);
        }

        return DB::transaction(function () use ($sourceId, $status, $profileKey, $profileVersion, $reason, $actorUserId): array {
            $facets = $this->lockFacets($sourceId);
            $occurredAt = now();
            $eventId = (int) DB::table('integration.source_conformance_events')->insertGetId([
                'event_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'previous_event_id' => $facets->conformance_event_id,
                'conformance_status' => $status,
                'profile_key' => $profileKey,
                'profile_version' => $profileVersion,
                'evidence_reference_sha256' => null,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ], 'source_conformance_event_id');
            DB::table('integration.source_status_facets')->where('source_id', $sourceId)->update([
                'conformance_status' => $status,
                'conformance_event_id' => $eventId,
                'conformance_profile_key' => $profileKey,
                'conformance_profile_version' => $profileVersion,
                'updated_at' => $occurredAt,
            ]);

            return ['facet' => 'conformance', 'status' => $status, 'eventId' => $eventId, 'occurredAtIso' => CarbonImmutable::parse($occurredAt)->toIso8601String()];
        });
    }

    /**
     * Record a governed contract-presence transition. Evidence-pointer only: the
     * caller passes an existing source_evidence_records id (never document
     * content). Its reference hash and expiry are copied for the projection.
     *
     * @return array<string, mixed>
     */
    public function recordContract(
        int $sourceId,
        string $status,
        ?int $evidenceRecordId,
        string $reason,
        ?int $actorUserId,
    ): array {
        if (! in_array($status, self::CONTRACT_STATUSES, true)) {
            throw ValidationException::withMessages(['contract_status' => 'The contract status is not supported.']);
        }
        $reason = $this->reason($reason);
        $evidence = null;
        if ($evidenceRecordId !== null) {
            $evidence = DB::table('integration.source_evidence_records')
                ->where('source_id', $sourceId)
                ->where('source_evidence_record_id', $evidenceRecordId)
                ->first();
            if ($evidence === null) {
                throw ValidationException::withMessages(['evidence_record_id' => 'The evidence pointer does not belong to the source.']);
            }
        }
        if ($status === 'active' && $evidence === null) {
            throw ValidationException::withMessages(['evidence_record_id' => 'An active contract requires an evidence pointer.']);
        }

        return DB::transaction(function () use ($sourceId, $status, $evidence, $reason, $actorUserId): array {
            $facets = $this->lockFacets($sourceId);
            $occurredAt = now();
            $eventId = (int) DB::table('integration.source_contract_events')->insertGetId([
                'event_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'previous_event_id' => $facets->contract_event_id,
                'contract_status' => $status,
                'source_evidence_record_id' => $evidence?->source_evidence_record_id,
                'evidence_reference_sha256' => $evidence?->reference_sha256,
                'expires_at' => $evidence?->expires_at,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ], 'source_contract_event_id');
            DB::table('integration.source_status_facets')->where('source_id', $sourceId)->update([
                'contract_status' => $status,
                'contract_event_id' => $eventId,
                'contract_expires_at' => $evidence?->expires_at,
                'updated_at' => $occurredAt,
            ]);

            return ['facet' => 'contract', 'status' => $status, 'eventId' => $eventId, 'occurredAtIso' => CarbonImmutable::parse($occurredAt)->toIso8601String()];
        });
    }

    /**
     * Record an operator incident transition. Optionally links to an existing
     * SLO breach (its incident SHA-256 reference is copied) so the incident and
     * observability lifecycles stay connected without collapsing.
     *
     * @return array<string, mixed>
     */
    public function recordIncident(
        int $sourceId,
        string $status,
        ?string $breachUuid,
        string $reason,
        ?int $actorUserId,
    ): array {
        if (! in_array($status, self::INCIDENT_STATUSES, true)) {
            throw ValidationException::withMessages(['incident_status' => 'The incident status is not supported.']);
        }
        $reason = $this->reason($reason);
        $breach = null;
        $incidentReferenceHash = null;
        if ($breachUuid !== null) {
            $breach = DB::table('integration.slo_breaches')
                ->where('source_id', $sourceId)
                ->where('breach_uuid', $breachUuid)
                ->first();
            if ($breach === null) {
                throw ValidationException::withMessages(['breach_uuid' => 'The linked SLO breach does not belong to the source.']);
            }
            $incidentReferenceHash = DB::table('integration.slo_breach_events')
                ->where('slo_breach_id', $breach->slo_breach_id)
                ->whereNotNull('incident_reference_hash')
                ->orderByDesc('slo_breach_event_id')
                ->value('incident_reference_hash');
        }

        return DB::transaction(function () use ($sourceId, $status, $breach, $incidentReferenceHash, $reason, $actorUserId): array {
            $facets = $this->lockFacets($sourceId);
            $occurredAt = now();
            $eventId = (int) DB::table('integration.source_incident_events')->insertGetId([
                'event_uuid' => (string) Str::uuid7(),
                'source_id' => $sourceId,
                'previous_event_id' => $facets->incident_event_id,
                'incident_status' => $status,
                'incident_reference_hash' => $incidentReferenceHash,
                'slo_breach_id' => $breach?->slo_breach_id,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ], 'source_incident_event_id');
            DB::table('integration.source_status_facets')->where('source_id', $sourceId)->update([
                'incident_status' => $status,
                'incident_event_id' => $eventId,
                'updated_at' => $occurredAt,
            ]);

            return ['facet' => 'incident', 'status' => $status, 'eventId' => $eventId, 'occurredAtIso' => CarbonImmutable::parse($occurredAt)->toIso8601String()];
        });
    }

    private function currentFacets(int $sourceId): object
    {
        return DB::table('integration.source_status_facets')->where('source_id', $sourceId)->firstOrFail();
    }

    private function lockFacets(int $sourceId): object
    {
        return DB::table('integration.source_status_facets')
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  callable(object): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function history(string $table, string $key, int $sourceId, callable $map): array
    {
        return DB::table('integration.'.$table)
            ->where('source_id', $sourceId)
            ->orderByDesc($key)
            ->get()
            ->map($map)
            ->all();
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A 10-500 character reason is required.']);
        }

        return $reason;
    }

    private function normalizeOptional(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
