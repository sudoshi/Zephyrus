<?php

namespace App\Services\Mobile;

use App\Models\Ops\OperationalEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OperationalActivityLedger
{
    public function __construct(
        private readonly PersonaRelayPolicy $relayPolicy,
        private readonly MobilePersonaCatalog $personas,
    ) {}

    /** @return array<string, mixed>|null */
    public function replay(string $eventType, array $attributes = []): ?array
    {
        if (! $this->hasReplayIdentity($attributes)) {
            return null;
        }

        if (! Schema::hasTable('ops.operational_events')) {
            return null;
        }

        $scope = $attributes['scope'] ?? [];
        $actorRole = $this->actorRole($attributes);
        $eventUuid = $this->eventUuid($eventType, $attributes, $scope, $actorRole);
        $fingerprint = $this->idempotencyFingerprint($eventType, $attributes, $scope, $actorRole);

        $event = OperationalEvent::query()
            ->with(['targets', 'entities'])
            ->where('event_uuid', $eventUuid)
            ->first();

        if ($event) {
            $this->assertMatchingFingerprint($event, $fingerprint);
        }

        return $event ? $this->shape($event) : null;
    }

    /** @return array<string, mixed>|null */
    public function record(string $eventType, array $attributes = []): ?array
    {
        if (! Schema::hasTable('ops.operational_events')) {
            throw new RuntimeException('Operational events table is not available.');
        }

        $scope = $attributes['scope'] ?? [];
        $status = $attributes['status'] ?? [];
        $recommendation = $attributes['recommendation'] ?? [];
        $domain = (string) ($attributes['domain'] ?? $this->domainFor($eventType));
        $actorRole = $this->actorRole($attributes);
        $relay = $attributes['relay'] ?? $this->relayPolicy->forEvent(
            $eventType,
            $domain,
            [...$scope, 'actor_role' => $actorRole],
        );
        $phiPolicy = array_merge([
            'list_safe' => true,
            'push_safe' => true,
            'requires_detail_auth' => ! empty($scope['patient_ref']) || ! empty($scope['encounter_ref']),
        ], $attributes['phi_policy'] ?? []);
        $eventUuid = $this->eventUuid($eventType, $attributes, $scope, $actorRole);
        $fingerprint = $this->idempotencyFingerprint($eventType, $attributes, $scope, $actorRole);

        return DB::transaction(function () use ($attributes, $eventType, $domain, $actorRole, $scope, $status, $recommendation, $relay, $phiPolicy, $eventUuid, $fingerprint): array {
            $existing = OperationalEvent::query()
                ->with(['targets', 'entities'])
                ->where('event_uuid', $eventUuid)
                ->first();

            if ($existing) {
                $this->assertMatchingFingerprint($existing, $fingerprint);

                return $this->shape($existing);
            }

            $payload = $attributes['payload'] ?? [];
            if ($fingerprint !== null) {
                $payload['_idempotency_fingerprint'] = $fingerprint;
            }

            $event = OperationalEvent::create([
                'event_uuid' => $eventUuid,
                'event_type' => $eventType,
                'occurred_at' => $attributes['occurred_at'] ?? now(),
                'actor_user_id' => $attributes['actor_user_id'] ?? null,
                'actor_role' => $actorRole,
                'source_surface' => $attributes['source_surface'] ?? 'hummingbird',
                'domain' => $domain,
                'scope' => $scope,
                'status' => $status,
                'recommendation' => $recommendation,
                'relay' => $relay,
                'phi_policy' => $phiPolicy,
                'payload' => $payload,
            ]);

            $this->storeTargets($event, $relay);
            $this->storeEntities($event, $scope, $attributes['entities'] ?? []);

            return $this->shape($event->refresh()->load(['targets', 'entities']));
        });
    }

    /** @return array{data: array<int, array<string, mixed>>, next_cursor: string|null} */
    public function feed(?User $user, string $roleId, ?string $cursor = null, int $limit = 50): array
    {
        if (! Schema::hasTable('ops.operational_events')) {
            return ['data' => [], 'next_cursor' => null];
        }

        $roleId = $this->personas->normalize($roleId, $user);

        $query = OperationalEvent::query()
            ->with(['targets', 'entities'])
            ->when($cursor, fn (Builder $q): Builder => $q->where('operational_event_id', '<', (int) $cursor))
            ->where(function (Builder $q) use ($roleId, $user): void {
                $q->where('actor_role', $roleId)
                    ->orWhereJsonContains('relay->affected_roles', $roleId)
                    ->orWhereHas('targets', fn (Builder $target): Builder => $target->where('target_role', $roleId));

                if ($user) {
                    $q->orWhereHas('targets', fn (Builder $target): Builder => $target->where('target_user_id', $user->getKey()));
                }
            })
            ->orderByDesc('operational_event_id')
            ->limit($limit + 1);

        $events = $query->get();
        $page = $events->take($limit);
        $nextCursor = $events->count() > $limit && $page->isNotEmpty() ? (string) $page->last()->operational_event_id : null;

        return [
            'data' => $page->map(fn (OperationalEvent $event): array => $this->shape($event))->values()->all(),
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $eventUuid, bool $detail = false): ?array
    {
        if (! Str::isUuid($eventUuid) || ! Schema::hasTable('ops.operational_events')) {
            return null;
        }

        $event = OperationalEvent::query()
            ->with(['targets', 'entities'])
            ->where('event_uuid', $eventUuid)
            ->first();

        return $event ? $this->shape($event, $detail) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function forEntity(string $entityType, string $entityRef, int $limit = 25): array
    {
        if (! Schema::hasTable('ops.operational_events')) {
            return [];
        }

        return OperationalEvent::query()
            ->with(['targets', 'entities'])
            ->whereHas('entities', fn (Builder $query): Builder => $query
                ->where('entity_type', $entityType)
                ->where('entity_ref', $entityRef))
            ->orderByDesc('operational_event_id')
            ->limit($limit)
            ->get()
            ->map(fn (OperationalEvent $event): array => $this->shape($event))
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function forPatient(string $patientRef, int $limit = 50): array
    {
        if (! Schema::hasTable('ops.operational_events')) {
            return [];
        }

        return OperationalEvent::query()
            ->with(['targets', 'entities'])
            ->whereHas('entities', fn (Builder $query): Builder => $query->where('patient_ref', $patientRef))
            ->orderByDesc('operational_event_id')
            ->limit($limit)
            ->get()
            ->map(fn (OperationalEvent $event): array => $this->shape($event))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function acknowledge(string $eventUuid, User $user, string $roleId): array
    {
        if (! Schema::hasTable('ops.operational_event_acknowledgements')) {
            throw new RuntimeException('Operational event acknowledgements table is not available.');
        }

        $event = OperationalEvent::query()->where('event_uuid', $eventUuid)->firstOrFail();
        $roleId = $this->personas->normalize($roleId, $user);

        if (! $this->canUserSeeEvent($event, $user, $roleId)) {
            throw new AuthorizationException('This event is not available to the current mobile persona.');
        }

        DB::table('ops.operational_event_acknowledgements')->updateOrInsert(
            ['operational_event_id' => $event->operational_event_id, 'user_id' => $user->getKey()],
            [
                'ack_role' => $roleId,
                'acknowledged_at' => now(),
                'metadata' => json_encode(['source_surface' => 'hummingbird']),
            ],
        );

        return [
            'event_uuid' => $eventUuid,
            'acknowledged' => true,
            'acknowledged_at' => now()->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function shape(OperationalEvent $event, bool $detail = false): array
    {
        $scope = $event->scope ?? [];
        $patientRef = $scope['patient_ref'] ?? null;

        unset($scope['patient_ref'], $scope['encounter_ref']);

        return [
            'event_uuid' => $event->event_uuid,
            'event_type' => $event->event_type,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'actor_user_id' => $event->actor_user_id,
            'actor_role' => $event->actor_role,
            'source_surface' => $event->source_surface,
            'domain' => $event->domain,
            // Map-shaped fields serialize as null when empty: a bare PHP [] JSON-encodes
            // as a list ([]), which strict clients (Swift Decodable) reject for objects.
            'scope' => $scope ?: null,
            'patient_context_ref' => $patientRef ? $this->patientContextRef($patientRef) : null,
            'status' => ($event->status ?? []) ?: null,
            'recommendation' => ($event->recommendation ?? []) ?: null,
            'relay' => ($event->relay ?? []) ?: null,
            'phi_policy' => ($event->phi_policy ?? []) ?: null,
            'entities' => $event->relationLoaded('entities') ? $event->entities->map(fn ($entity): array => [
                'entity_type' => $entity->entity_type,
                'entity_ref' => $this->entityRefForPayload($entity->entity_type, $entity->entity_ref),
                'patient_context_ref' => $entity->patient_ref ? $this->patientContextRef($entity->patient_ref) : null,
                'encounter_ref' => null,
            ])->values()->all() : [],
        ];
    }

    /** @param array<string, mixed> $relay */
    private function storeTargets(OperationalEvent $event, array $relay): void
    {
        $notifyNow = collect($relay['notify_now'] ?? []);
        $activityOnly = collect($relay['activity_only'] ?? []);
        $roles = collect($relay['affected_roles'] ?? [])
            ->merge($notifyNow)
            ->merge($activityOnly)
            ->filter()
            ->unique()
            ->values();

        foreach ($roles as $role) {
            DB::table('ops.operational_event_targets')->insert([
                'operational_event_id' => $event->operational_event_id,
                'target_role' => $role,
                'delivery' => $notifyNow->contains($role) ? 'push' : 'activity',
                'push_tier' => $notifyNow->contains($role) ? ($relay['push_tier'] ?? 'warning') : 'activity',
                'created_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $scope @param array<int, array<string, mixed>> $entities */
    private function storeEntities(OperationalEvent $event, array $scope, array $entities): void
    {
        $rows = collect($entities);

        foreach ([
            'patient_ref' => 'patient',
            'encounter_ref' => 'encounter',
            'bed_id' => 'bed',
            'unit_id' => 'unit',
            'action_uuid' => 'action',
            'approval_uuid' => 'approval',
        ] as $key => $type) {
            if (! empty($scope[$key])) {
                $rows->push([
                    'entity_type' => $type,
                    'entity_ref' => (string) $scope[$key],
                    'patient_ref' => $scope['patient_ref'] ?? null,
                    'encounter_ref' => $scope['encounter_ref'] ?? null,
                ]);
            }
        }

        $rows
            ->filter(fn (array $row): bool => ! empty($row['entity_type']) && ! empty($row['entity_ref']))
            ->unique(fn (array $row): string => $row['entity_type'].'|'.$row['entity_ref'])
            ->each(fn (array $row): bool => DB::table('ops.operational_event_entities')->insert([
                'operational_event_id' => $event->operational_event_id,
                'entity_type' => $row['entity_type'],
                'entity_ref' => (string) $row['entity_ref'],
                'patient_ref' => $row['patient_ref'] ?? ($scope['patient_ref'] ?? null),
                'encounter_ref' => $row['encounter_ref'] ?? ($scope['encounter_ref'] ?? null),
                'created_at' => now(),
            ]));
    }

    private function domainFor(string $eventType): string
    {
        return match (true) {
            str_starts_with($eventType, 'bed_request.'), str_starts_with($eventType, 'barrier.') => 'rtdc',
            str_starts_with($eventType, 'transport.') => 'transport',
            str_starts_with($eventType, 'evs.') => 'evs',
            str_starts_with($eventType, 'staffing.') => 'staffing',
            str_starts_with($eventType, 'ancillary.') => 'ancillary',
            str_starts_with($eventType, 'or.') => 'or',
            str_starts_with($eventType, 'recommendation.'), str_starts_with($eventType, 'action.') => 'ops',
            default => 'ops',
        };
    }

    private function patientContextRef(string $patientRef): string
    {
        $key = (string) config('app.key', 'zephyrus');

        return 'ptok_'.substr(hash_hmac('sha256', $patientRef, $key), 0, 24);
    }

    private function eventUuid(string $eventType, array $attributes, array $scope, ?string $actorRole): string
    {
        if (! empty($attributes['event_uuid']) && is_string($attributes['event_uuid']) && Str::isUuid($attributes['event_uuid'])) {
            return $attributes['event_uuid'];
        }

        $key = $attributes['idempotency_key'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            return (string) Str::uuid();
        }

        $hash = hash('sha256', implode('|', [
            'hummingbird-mobile-ledger',
            $eventType,
            (string) ($attributes['actor_user_id'] ?? ''),
            (string) $actorRole,
            (string) ($attributes['source_surface'] ?? 'hummingbird'),
            json_encode($this->idempotencyScope($scope), JSON_THROW_ON_ERROR),
            mb_substr(trim($key), 0, 200),
        ]));

        return sprintf(
            '%s-%s-5%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec($hash[16]) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    private function actorRole(array $attributes): ?string
    {
        return isset($attributes['actor_role'])
            ? $this->personas->normalize($attributes['actor_role'])
            : null;
    }

    private function hasReplayIdentity(array $attributes): bool
    {
        if (! empty($attributes['event_uuid']) && is_string($attributes['event_uuid']) && Str::isUuid($attributes['event_uuid'])) {
            return true;
        }

        $key = $attributes['idempotency_key'] ?? null;

        return is_string($key) && trim($key) !== '';
    }

    private function idempotencyFingerprint(string $eventType, array $attributes, array $scope, ?string $actorRole): ?string
    {
        if (! $this->hasReplayIdentity($attributes)) {
            return null;
        }

        return hash('sha256', json_encode([
            'event_type' => $eventType,
            'actor_user_id' => (string) ($attributes['actor_user_id'] ?? ''),
            'actor_role' => (string) $actorRole,
            'source_surface' => (string) ($attributes['source_surface'] ?? 'hummingbird'),
            'scope' => $this->idempotencyScope($scope),
            'request' => $attributes['idempotency_payload'] ?? [
                'status' => $attributes['status'] ?? [],
                'recommendation' => $attributes['recommendation'] ?? [],
                'payload' => $attributes['payload'] ?? [],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function assertMatchingFingerprint(OperationalEvent $event, ?string $fingerprint): void
    {
        $existing = $event->payload['_idempotency_fingerprint'] ?? null;

        if ($fingerprint !== null && is_string($existing) && $existing !== $fingerprint) {
            throw new ConflictHttpException('Idempotency-Key was already used for a different mobile write payload.');
        }
    }

    private function idempotencyScope(array $scope): array
    {
        return collect($scope)
            ->only([
                'action_uuid',
                'approval_uuid',
                'barrier_id',
                'bed_request_id',
                'evs_request_id',
                'staffing_request_id',
                'transport_request_id',
            ])
            ->sortKeys()
            ->all();
    }

    private function entityRefForPayload(string $entityType, string $entityRef): ?string
    {
        return match ($entityType) {
            'patient' => $this->patientContextRef($entityRef),
            'encounter' => null,
            default => $entityRef,
        };
    }

    private function canUserSeeEvent(OperationalEvent $event, User $user, string $roleId): bool
    {
        if ($event->actor_user_id && (int) $event->actor_user_id === (int) $user->getKey()) {
            return true;
        }

        if ($event->actor_role === $roleId) {
            return true;
        }

        $relay = $event->relay ?? [];
        if (in_array($roleId, $relay['affected_roles'] ?? [], true)) {
            return true;
        }

        return DB::table('ops.operational_event_targets')
            ->where('operational_event_id', $event->operational_event_id)
            ->where(function ($query) use ($user, $roleId): void {
                $query->where('target_role', $roleId)
                    ->orWhere('target_user_id', $user->getKey());
            })
            ->exists();
    }
}
