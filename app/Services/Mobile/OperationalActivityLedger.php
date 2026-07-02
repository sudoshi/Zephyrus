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

class OperationalActivityLedger
{
    public function __construct(
        private readonly PersonaRelayPolicy $relayPolicy,
        private readonly MobilePersonaCatalog $personas,
    ) {}

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
        $actorRole = isset($attributes['actor_role'])
            ? $this->personas->normalize($attributes['actor_role'])
            : null;
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

        return DB::transaction(function () use ($attributes, $eventType, $domain, $actorRole, $scope, $status, $recommendation, $relay, $phiPolicy): array {
            $event = OperationalEvent::create([
                'event_uuid' => $attributes['event_uuid'] ?? (string) Str::uuid(),
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
                'payload' => $attributes['payload'] ?? [],
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
            'scope' => $scope,
            'patient_context_ref' => $patientRef ? $this->patientContextRef($patientRef) : null,
            'status' => $event->status ?? [],
            'recommendation' => $event->recommendation ?? [],
            'relay' => $event->relay ?? [],
            'phi_policy' => $event->phi_policy ?? [],
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
