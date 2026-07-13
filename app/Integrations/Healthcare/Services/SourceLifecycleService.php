<?php

namespace App\Integrations\Healthcare\Services;

use App\Services\Governance\GovernanceViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SourceLifecycleService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'draft' => ['discovery', 'configured', 'retired'],
        'discovery' => ['draft', 'configured', 'retired'],
        'configured' => ['discovery', 'validating', 'retired'],
        'validating' => ['configured', 'approved', 'suspended', 'retired'],
        'approved' => ['configured', 'scheduled', 'live', 'suspended', 'retired'],
        'scheduled' => ['configured', 'approved', 'live', 'suspended', 'retired'],
        'live' => ['configured', 'degraded', 'suspended', 'retired'],
        'degraded' => ['configured', 'approved', 'live', 'suspended', 'retired'],
        'suspended' => ['configured', 'validating', 'approved', 'live', 'retired'],
        'retired' => [],
    ];

    /** @var array<string, array{active: string, go_live: string}> */
    private const LEGACY_PROJECTION = [
        'draft' => ['active' => 'inactive', 'go_live' => 'not_started'],
        'discovery' => ['active' => 'inactive', 'go_live' => 'planning'],
        'configured' => ['active' => 'inactive', 'go_live' => 'planning'],
        'validating' => ['active' => 'testing', 'go_live' => 'testing'],
        'approved' => ['active' => 'testing', 'go_live' => 'ready'],
        'scheduled' => ['active' => 'testing', 'go_live' => 'ready'],
        'live' => ['active' => 'active', 'go_live' => 'live'],
        'degraded' => ['active' => 'degraded', 'go_live' => 'live'],
        'suspended' => ['active' => 'disabled', 'go_live' => 'paused'],
        'retired' => ['active' => 'disabled', 'go_live' => 'retired'],
    ];

    public function stateForLegacyStatuses(string $activeStatus, string $goLiveStatus): string
    {
        return match (true) {
            $goLiveStatus === 'retired' => 'retired',
            $activeStatus === 'degraded' => 'degraded',
            $activeStatus === 'active' || $goLiveStatus === 'live' => 'live',
            $activeStatus === 'disabled' || $goLiveStatus === 'paused' => 'suspended',
            $goLiveStatus === 'ready' => 'approved',
            $activeStatus === 'testing' || $goLiveStatus === 'testing' => 'validating',
            $goLiveStatus === 'planning' => 'discovery',
            default => 'draft',
        };
    }

    public function initialize(
        int $sourceId,
        ?int $actorUserId,
        string $reason,
        ?string $governedChangeUuid = null,
    ): object {
        return DB::transaction(function () use ($sourceId, $actorUserId, $reason, $governedChangeUuid): object {
            $source = $this->source($sourceId, lock: true);
            $this->initializeStatusFacets($sourceId);
            $existing = DB::table('integration.source_lifecycle_events')
                ->where('source_id', $sourceId)->orderByDesc('source_lifecycle_event_id')->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($source->current_configuration_version_id === null) {
                throw ValidationException::withMessages([
                    'configuration_version_id' => 'A source lifecycle requires an immutable configuration version.',
                ]);
            }

            return $this->insertEvent(
                $source,
                null,
                (string) $source->lifecycle_state,
                $reason,
                $actorUserId,
                $governedChangeUuid,
                ['initial' => true],
            );
        });
    }

    /**
     * Bootstrap the three governed/operator status facets for a new source at
     * their base states (conformance not_started, contract none, incident none).
     * Each projection column is backed by a governing initial event so the facet
     * separation is real from the moment the source exists. Idempotent.
     */
    private function initializeStatusFacets(int $sourceId): void
    {
        if (! Schema::hasTable('integration.source_status_facets')
            || DB::table('integration.source_status_facets')->where('source_id', $sourceId)->exists()) {
            return;
        }
        $occurredAt = now();
        $conformanceEventId = (int) DB::table('integration.source_conformance_events')->insertGetId([
            'event_uuid' => (string) Str::uuid7(),
            'source_id' => $sourceId,
            'previous_event_id' => null,
            'conformance_status' => 'not_started',
            'profile_key' => null,
            'profile_version' => null,
            'evidence_reference_sha256' => null,
            'reason' => 'Initial source conformance facet in the not-started state.',
            'actor_user_id' => null,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ], 'source_conformance_event_id');
        $contractEventId = (int) DB::table('integration.source_contract_events')->insertGetId([
            'event_uuid' => (string) Str::uuid7(),
            'source_id' => $sourceId,
            'previous_event_id' => null,
            'contract_status' => 'none',
            'source_evidence_record_id' => null,
            'evidence_reference_sha256' => null,
            'expires_at' => null,
            'reason' => 'Initial source contract facet in the none state.',
            'actor_user_id' => null,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ], 'source_contract_event_id');
        $incidentEventId = (int) DB::table('integration.source_incident_events')->insertGetId([
            'event_uuid' => (string) Str::uuid7(),
            'source_id' => $sourceId,
            'previous_event_id' => null,
            'incident_status' => 'none',
            'incident_reference_hash' => null,
            'slo_breach_id' => null,
            'reason' => 'Initial source incident facet in the cleared state.',
            'actor_user_id' => null,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ], 'source_incident_event_id');
        DB::table('integration.source_status_facets')->insert([
            'source_id' => $sourceId,
            'conformance_status' => 'not_started',
            'conformance_event_id' => $conformanceEventId,
            'conformance_profile_key' => null,
            'conformance_profile_version' => null,
            'contract_status' => 'none',
            'contract_event_id' => $contractEventId,
            'contract_expires_at' => null,
            'incident_status' => 'none',
            'incident_event_id' => $incidentEventId,
            'updated_at' => $occurredAt,
        ]);
    }

    public function transition(
        int $sourceId,
        string $toState,
        string $reason,
        ?int $actorUserId,
        ?string $governedChangeUuid = null,
        array $metadata = [],
    ): object {
        return DB::transaction(function () use (
            $sourceId,
            $toState,
            $reason,
            $actorUserId,
            $governedChangeUuid,
            $metadata,
        ): object {
            $source = $this->source($sourceId, lock: true);
            $fromState = (string) $source->lifecycle_state;
            $this->assertTransition($fromState, $toState);
            if ($source->current_configuration_version_id === null) {
                throw ValidationException::withMessages([
                    'configuration_version_id' => 'A lifecycle transition requires an immutable configuration version.',
                ]);
            }

            $event = $this->insertEvent(
                $source,
                $fromState,
                $toState,
                $reason,
                $actorUserId,
                $governedChangeUuid,
                $metadata,
            );
            $projection = self::LEGACY_PROJECTION[$toState];
            DB::table('integration.sources')->where('source_id', $sourceId)->update([
                'lifecycle_state' => $toState,
                'active_status' => $projection['active'],
                'go_live_status' => $projection['go_live'],
                'lifecycle_changed_at' => now(),
                'updated_at' => now(),
            ]);

            return $event;
        });
    }

    public function transitionToLive(
        int $sourceId,
        string $reason,
        ?int $actorUserId,
        string $governedChangeUuid,
    ): void {
        DB::transaction(function () use ($sourceId, $reason, $actorUserId, $governedChangeUuid): void {
            $state = (string) $this->source($sourceId, lock: true)->lifecycle_state;
            if (! in_array($state, ['validating', 'approved', 'scheduled', 'degraded', 'suspended'], true)) {
                throw new GovernanceViolation(
                    'source_lifecycle_invalid',
                    'The source lifecycle no longer permits the approved production activation.',
                );
            }

            if (in_array($state, ['validating', 'degraded', 'suspended'], true)) {
                $this->transition(
                    $sourceId,
                    'approved',
                    $reason,
                    $actorUserId,
                    $governedChangeUuid,
                    ['activation_stage' => 'approved'],
                );
                $state = 'approved';
            }
            if ($state === 'approved') {
                $this->transition(
                    $sourceId,
                    'scheduled',
                    $reason,
                    $actorUserId,
                    $governedChangeUuid,
                    ['activation_stage' => 'scheduled'],
                );
            }
            $this->transition(
                $sourceId,
                'live',
                $reason,
                $actorUserId,
                $governedChangeUuid,
                ['activation_stage' => 'live'],
            );
        });
    }

    public function transitionToScheduled(
        int $sourceId,
        string $reason,
        ?int $actorUserId,
        string $governedChangeUuid,
        array $metadata = [],
    ): void {
        DB::transaction(function () use (
            $sourceId,
            $reason,
            $actorUserId,
            $governedChangeUuid,
            $metadata,
        ): void {
            $state = (string) $this->source($sourceId, lock: true)->lifecycle_state;
            if (! in_array($state, ['validating', 'approved', 'degraded', 'suspended'], true)) {
                throw new GovernanceViolation(
                    'source_lifecycle_invalid',
                    'The source lifecycle no longer permits the approved production schedule.',
                );
            }

            if (in_array($state, ['validating', 'degraded', 'suspended'], true)) {
                $this->transition(
                    $sourceId,
                    'approved',
                    $reason,
                    $actorUserId,
                    $governedChangeUuid,
                    [...$metadata, 'activation_stage' => 'approved'],
                );
            }
            $this->transition(
                $sourceId,
                'scheduled',
                $reason,
                $actorUserId,
                $governedChangeUuid,
                [...$metadata, 'activation_stage' => 'scheduled'],
            );
        });
    }

    public function resetAfterConfigurationChange(
        int $sourceId,
        string $reason,
        ?int $actorUserId,
        ?string $governedChangeUuid = null,
    ): void {
        $state = (string) $this->source($sourceId)->lifecycle_state;
        if (in_array($state, ['draft', 'discovery', 'configured'], true)) {
            return;
        }

        $this->transition(
            $sourceId,
            'configured',
            $reason,
            $actorUserId,
            $governedChangeUuid,
            ['configuration_invalidated_prior_validation' => true],
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(int $sourceId): array
    {
        $this->source($sourceId);

        return DB::table('integration.source_lifecycle_events as event')
            ->join(
                'integration.source_configuration_versions as version',
                'version.source_configuration_version_id',
                '=',
                'event.configuration_version_id',
            )
            ->where('event.source_id', $sourceId)
            ->orderByDesc('event.source_lifecycle_event_id')
            ->get([
                'event.*',
                'version.version_number',
                'version.configuration_sha256',
            ])
            ->map(fn (object $event): array => [
                'lifecycleEventId' => (int) $event->source_lifecycle_event_id,
                'eventUuid' => (string) $event->event_uuid,
                'sourceId' => (int) $event->source_id,
                'configurationVersionId' => (int) $event->configuration_version_id,
                'configurationVersionNumber' => (int) $event->version_number,
                'configurationSha256' => (string) $event->configuration_sha256,
                'fromState' => $event->from_state,
                'toState' => (string) $event->to_state,
                'reason' => (string) $event->reason,
                'changedByUserId' => $event->changed_by_user_id !== null ? (int) $event->changed_by_user_id : null,
                'governedChangeRequestUuid' => $event->governed_change_request_uuid,
                'occurredAtIso' => $event->occurred_at !== null ? (string) $event->occurred_at : null,
            ])->all();
    }

    private function source(int $sourceId, bool $lock = false): object
    {
        $query = DB::table('integration.sources')->where('source_id', $sourceId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function assertTransition(string $fromState, string $toState): void
    {
        if (! array_key_exists($fromState, self::TRANSITIONS)
            || ! in_array($toState, self::TRANSITIONS[$fromState], true)) {
            throw ValidationException::withMessages([
                'lifecycle_state' => "The source cannot transition from {$fromState} to {$toState}.",
            ]);
        }
    }

    private function insertEvent(
        object $source,
        ?string $fromState,
        string $toState,
        string $reason,
        ?int $actorUserId,
        ?string $governedChangeUuid,
        array $metadata,
    ): object {
        $reason = $this->reason($reason);
        $eventId = (int) DB::table('integration.source_lifecycle_events')->insertGetId([
            'event_uuid' => (string) Str::uuid7(),
            'source_id' => $source->source_id,
            'configuration_version_id' => $source->current_configuration_version_id,
            'from_state' => $fromState,
            'to_state' => $toState,
            'reason' => $reason,
            'changed_by_user_id' => $actorUserId,
            'governed_change_request_uuid' => $governedChangeUuid,
            'metadata' => json_encode((object) $metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ], 'source_lifecycle_event_id');

        return DB::table('integration.source_lifecycle_events')
            ->where('source_lifecycle_event_id', $eventId)->firstOrFail();
    }

    private function reason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'A 10-500 character lifecycle reason is required.',
            ]);
        }

        return $reason;
    }
}
