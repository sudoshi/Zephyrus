<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportAssignment;
use App\Models\Transport\TransportEvent;
use App\Models\Transport\TransportHandoffEvidence;
use App\Models\Transport\TransportRequest;
use App\Models\Transport\TransportResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransportLifecycleService
{
    /** @var array<string,list<string>> */
    public const TRANSITIONS = [
        'requested' => ['accepted', 'queued', 'assigned', 'escalated', 'canceled', 'failed'],
        'accepted' => ['queued', 'assigned', 'escalated', 'canceled', 'failed'],
        'queued' => ['assigned', 'escalated', 'canceled', 'failed'],
        'assigned' => ['dispatched', 'escalated', 'canceled', 'failed'],
        'dispatched' => ['arrived_pickup', 'escalated', 'canceled', 'failed'],
        'arrived_pickup' => ['patient_ready', 'patient_not_ready', 'picked_up', 'escalated', 'canceled', 'failed'],
        'patient_ready' => ['picked_up', 'escalated', 'canceled', 'failed'],
        'patient_not_ready' => ['patient_ready', 'escalated', 'canceled', 'failed'],
        'picked_up' => ['en_route', 'escalated', 'failed'],
        'en_route' => ['arrived_destination', 'escalated', 'failed'],
        'arrived_destination' => ['handoff_started', 'handoff_complete', 'completed', 'escalated', 'failed'],
        'handoff_started' => ['handoff_complete', 'escalated', 'failed'],
        'handoff_complete' => ['completed', 'escalated', 'failed'],
        'escalated' => [],
        'completed' => [],
        'canceled' => [],
        'failed' => [],
    ];

    public const TERMINAL_STATUSES = ['completed', 'canceled', 'failed'];

    /** @var list<string> */
    private const DISPATCH_TARGETS = ['accepted', 'queued', 'assigned', 'canceled'];

    /** @var list<string> */
    private const REASON_REQUIRED_TARGETS = ['patient_not_ready', 'escalated', 'canceled', 'failed'];

    public function create(array $data, User $actor, string $idempotencyKey, string $source = 'web'): TransportRequest
    {
        if (! $actor->can('requestTransportOperations')) {
            throw new AuthorizationException('Transport request authorization is required.');
        }
        $payload = $data;

        return $this->executeIdempotent(
            requestId: null,
            commandType: 'create',
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            actor: $actor,
            source: $source,
            operation: function () use ($actor, $data): TransportRequest {
                $request = TransportRequest::query()->create(array_merge($data, [
                    'request_uuid' => (string) Str::uuid(),
                    'status' => 'requested',
                    'requested_by' => "user:{$actor->id}",
                    'requested_at' => $data['requested_at'] ?? now(),
                    'assigned_at' => null,
                    'dispatched_at' => null,
                    'completed_at' => null,
                    'assigned_team' => null,
                    'assigned_vendor' => null,
                    'handoff' => null,
                    'handoff_required' => in_array(
                        (string) $data['request_type'],
                        (array) config('transport.handoff_required_types', []),
                        true,
                    ),
                    'escalated_from_status' => null,
                    'lifecycle_version' => 1,
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                    'is_deleted' => false,
                ]));

                $this->recordEvent($request, 'transport.requested', null, 'requested', [
                    'origin' => $request->origin,
                    'destination' => $request->destination,
                    'priority' => $request->priority,
                    'request_type' => $request->request_type,
                    'handoff_required' => $request->handoff_required,
                ], $actor->id);

                return $request->refresh();
            },
        );
    }

    public function assign(
        TransportRequest $request,
        array $data,
        User $actor,
        string $idempotencyKey,
        string $source = 'web',
        bool $claim = false,
    ): TransportRequest {
        if ($claim) {
            $this->authorizeFieldOperator($actor);
        } else {
            $this->authorizeDispatcher($actor);
        }

        $payload = [
            'resource_key' => $data['resource_key'] ?? null,
            'assigned_team' => $data['assigned_team'] ?? null,
            'assigned_vendor' => $data['assigned_vendor'] ?? null,
            'capacity_units' => (int) ($data['capacity_units'] ?? 1),
            'note' => $data['note'] ?? null,
            'claim' => $claim,
        ];

        return $this->executeIdempotent(
            requestId: (int) $request->transport_request_id,
            commandType: $claim ? 'claim' : 'assign',
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            actor: $actor,
            source: $source,
            operation: function () use ($actor, $claim, $data, $request): TransportRequest {
                $locked = $this->lockedRequest((int) $request->transport_request_id);
                $this->assertTransitionAllowed($locked, 'assigned');

                if (TransportAssignment::query()
                    ->where('transport_request_id', $locked->transport_request_id)
                    ->where('status', 'active')
                    ->whereNull('released_at')
                    ->exists()) {
                    throw new ConflictHttpException('The transport request already has an active resource assignment.');
                }

                $resource = $claim
                    ? $this->transporterResource($actor)
                    : $this->resolveConfiguredResource($data);
                $resource = TransportResource::query()
                    ->whereKey($resource->transport_resource_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $units = max(1, (int) ($data['capacity_units'] ?? 1));
                $busy = (int) TransportAssignment::query()
                    ->where('transport_resource_id', $resource->transport_resource_id)
                    ->where('status', 'active')
                    ->whereNull('released_at')
                    ->sum('capacity_units');

                if ($busy + $units > $resource->capacity) {
                    throw new ConflictHttpException("{$resource->display_name} has no remaining transport capacity.");
                }

                $capabilities = $resource->capabilities ?? [];
                if ($capabilities !== [] && ! in_array($locked->transport_mode, $capabilities, true)) {
                    throw ValidationException::withMessages([
                        'resource_key' => "{$resource->display_name} is not configured for {$locked->transport_mode} transport.",
                    ]);
                }

                TransportAssignment::query()->create([
                    'assignment_uuid' => (string) Str::uuid(),
                    'transport_request_id' => $locked->transport_request_id,
                    'transport_resource_id' => $resource->transport_resource_id,
                    'capacity_units' => $units,
                    'status' => 'active',
                    'reserved_from' => now(),
                    'assigned_by_user_id' => $actor->id,
                    'metadata' => ['claim' => $claim],
                ]);

                $from = $locked->status;
                $locked->update([
                    'status' => 'assigned',
                    'assigned_team' => in_array($resource->resource_type, ['team', 'transporter'], true)
                        ? $resource->display_name
                        : null,
                    'assigned_vendor' => $resource->resource_type === 'vendor' ? $resource->display_name : null,
                    'assigned_at' => now(),
                    'escalated_from_status' => null,
                    'lifecycle_version' => ((int) $locked->lifecycle_version) + 1,
                    'updated_by_user_id' => $actor->id,
                ]);

                $this->recordEvent($locked, $claim ? 'transport.claimed' : 'transport.assigned', $from, 'assigned', [
                    'resource_key' => $resource->resource_key,
                    'resource_type' => $resource->resource_type,
                    'resource_name' => $resource->display_name,
                    'capacity_units' => $units,
                    'note' => $data['note'] ?? null,
                ], $actor->id);

                return $locked->refresh()->load('activeAssignment.resource');
            },
        );
    }

    public function transition(
        TransportRequest $request,
        string $targetStatus,
        array $payload,
        User $actor,
        string $idempotencyKey,
        string $source = 'web',
    ): TransportRequest {
        if ($targetStatus === 'handoff_complete') {
            throw ValidationException::withMessages([
                'status' => 'Handoff completion requires the structured handoff endpoint.',
            ]);
        }

        return $this->executeIdempotent(
            requestId: (int) $request->transport_request_id,
            commandType: "transition:{$targetStatus}",
            idempotencyKey: $idempotencyKey,
            payload: ['status' => $targetStatus, 'payload' => $payload],
            actor: $actor,
            source: $source,
            operation: function () use ($actor, $payload, $request, $targetStatus): TransportRequest {
                $locked = $this->lockedRequest((int) $request->transport_request_id);
                $this->assertTransitionAllowed($locked, $targetStatus);
                $this->authorizeTransition($actor, $locked, $targetStatus);
                $this->assertRequiredReason($targetStatus, $payload);

                if ($targetStatus === 'assigned'
                    && ! ($locked->status === 'escalated' && $locked->activeAssignment !== null)) {
                    throw ValidationException::withMessages([
                        'status' => 'Assignment must use the governed assignment or mobile claim endpoint.',
                    ]);
                }

                if ($targetStatus === 'completed'
                    && $locked->handoff_required
                    && ! TransportHandoffEvidence::query()->where('transport_request_id', $locked->transport_request_id)->exists()) {
                    throw ValidationException::withMessages([
                        'status' => 'Accepted structured handoff evidence is required before completion.',
                    ]);
                }

                $from = $locked->status;
                $updates = [
                    'status' => $targetStatus,
                    'lifecycle_version' => ((int) $locked->lifecycle_version) + 1,
                    'updated_by_user_id' => $actor->id,
                ];
                if ($targetStatus === 'dispatched' && $locked->dispatched_at === null) {
                    $updates['dispatched_at'] = now();
                }
                if (in_array($targetStatus, self::TERMINAL_STATUSES, true) && $locked->completed_at === null) {
                    $updates['completed_at'] = now();
                }
                if ($targetStatus === 'escalated') {
                    $updates['escalated_from_status'] = $from;
                } elseif ($from === 'escalated') {
                    $updates['escalated_from_status'] = null;
                }

                $locked->update($updates);
                if (in_array($targetStatus, self::TERMINAL_STATUSES, true)) {
                    $this->releaseAssignment($locked, $targetStatus, $actor->id);
                }

                $eventType = match ($targetStatus) {
                    'arrived_pickup' => 'transport.arrived_pickup',
                    'arrived_destination' => 'transport.arrived_destination',
                    'patient_not_ready' => 'transport.not_ready',
                    default => "transport.{$targetStatus}",
                };
                $this->recordEvent($locked, $eventType, $from, $targetStatus, $payload, $actor->id);

                return $locked->refresh()->load('activeAssignment.resource', 'handoffEvidence');
            },
        );
    }

    public function completeHandoff(
        TransportRequest $request,
        array $data,
        User $actor,
        string $idempotencyKey,
        string $source = 'web',
    ): TransportRequest {
        return $this->executeIdempotent(
            requestId: (int) $request->transport_request_id,
            commandType: 'handoff',
            idempotencyKey: $idempotencyKey,
            payload: $data,
            actor: $actor,
            source: $source,
            operation: function () use ($actor, $data, $request): TransportRequest {
                $locked = $this->lockedRequest((int) $request->transport_request_id);
                $this->authorizeTransition($actor, $locked, 'handoff_complete');
                if (! in_array($locked->status, ['arrived_destination', 'handoff_started', 'handoff_complete'], true)
                    || ($locked->status === 'handoff_complete' && $locked->handoffEvidence !== null)) {
                    throw new ConflictHttpException("Cannot complete handoff from {$locked->status}.");
                }
                if (($data['acceptance_status'] ?? null) === null) {
                    throw ValidationException::withMessages([
                        'acceptance_status' => 'Receiver acceptance is required.',
                    ]);
                }
                if ($data['acceptance_status'] === 'accepted_with_risks'
                    && empty($data['outstanding_risks'])) {
                    throw ValidationException::withMessages([
                        'outstanding_risks' => 'At least one outstanding risk is required for accepted-with-risks handoff.',
                    ]);
                }
                $acceptedAt = isset($data['accepted_at']) ? Carbon::parse($data['accepted_at']) : now();
                $earliestAcceptance = TransportEvent::query()
                    ->where('transport_request_id', $locked->transport_request_id)
                    ->where('event_type', 'transport.arrived_destination')
                    ->latest('occurred_at')
                    ->value('occurred_at') ?? $locked->requested_at;
                if ($earliestAcceptance && $acceptedAt->lt(Carbon::parse($earliestAcceptance))) {
                    throw ValidationException::withMessages([
                        'accepted_at' => 'Receiver acceptance cannot precede destination arrival.',
                    ]);
                }

                $from = $locked->status;
                if ($from === 'arrived_destination') {
                    $locked->update([
                        'status' => 'handoff_started',
                        'lifecycle_version' => ((int) $locked->lifecycle_version) + 1,
                        'updated_by_user_id' => $actor->id,
                    ]);
                    $this->recordEvent($locked, 'transport.handoff_started', $from, 'handoff_started', [], $actor->id);
                    $from = 'handoff_started';
                }

                $evidence = TransportHandoffEvidence::query()->create([
                    'evidence_uuid' => (string) Str::uuid(),
                    'transport_request_id' => $locked->transport_request_id,
                    'handoff_to' => $data['handoff_to'],
                    'receiver_role' => $data['receiver_role'],
                    'acceptance_status' => $data['acceptance_status'],
                    'accepted_at' => $acceptedAt,
                    'handoff_summary' => $data['handoff_summary'] ?? null,
                    'documents' => $data['documents'] ?? [],
                    'outstanding_risks' => $data['outstanding_risks'] ?? [],
                    'actor_user_id' => $actor->id,
                ]);

                $locked->update([
                    'status' => 'handoff_complete',
                    'handoff' => [
                        'evidence_uuid' => $evidence->evidence_uuid,
                        'handoff_to' => $evidence->handoff_to,
                        'receiver_role' => $evidence->receiver_role,
                        'acceptance_status' => $evidence->acceptance_status,
                        'accepted_at' => $evidence->accepted_at?->toIso8601String(),
                        'handoff_summary' => $evidence->handoff_summary,
                        'documents' => $evidence->documents ?? [],
                        'outstanding_risks' => $evidence->outstanding_risks ?? [],
                    ],
                    'lifecycle_version' => ((int) $locked->lifecycle_version) + 1,
                    'updated_by_user_id' => $actor->id,
                ]);
                $this->recordEvent($locked, 'transport.handoff_complete', $from, 'handoff_complete', [
                    'evidence_uuid' => $evidence->evidence_uuid,
                    'handoff_to' => $evidence->handoff_to,
                    'receiver_role' => $evidence->receiver_role,
                    'acceptance_status' => $evidence->acceptance_status,
                    'outstanding_risk_count' => count($evidence->outstanding_risks ?? []),
                    'document_count' => count($evidence->documents ?? []),
                ], $actor->id);

                return $locked->refresh()->load('activeAssignment.resource', 'handoffEvidence');
            },
        );
    }

    /** @return list<string> */
    public function allowedTransitions(TransportRequest $request, User $actor): array
    {
        return array_values(array_filter(
            $this->graphTargets($request),
            function (string $target) use ($actor, $request): bool {
                if ($target === 'handoff_complete') {
                    return false;
                }
                if ($target === 'completed' && $request->handoff_required && ! $request->handoffEvidence) {
                    return false;
                }

                return $this->actorCanTransition($actor, $request, $target);
            },
        ));
    }

    public function canAssign(TransportRequest $request, User $actor): bool
    {
        return $actor->can('manageTransportDispatch')
            && in_array('assigned', $this->graphTargets($request), true)
            && $request->activeAssignment === null;
    }

    public function canCompleteHandoff(TransportRequest $request, User $actor): bool
    {
        return in_array($request->status, ['arrived_destination', 'handoff_started', 'handoff_complete'], true)
            && $request->handoffEvidence === null
            && $this->actorCanTransition($actor, $request, 'handoff_complete');
    }

    /** @return list<array<string,mixed>> */
    public function resourceOptions(?string $type = null): array
    {
        $stored = TransportResource::query()
            ->where('is_active', true)
            ->when($type, fn ($query, $type) => $query->where('resource_type', $type))
            ->orderBy('display_name')
            ->get()
            ->keyBy('resource_key');
        $definitions = collect($this->configuredResources())
            ->when($type, fn ($items) => $items->where('type', $type));

        $rows = $definitions->map(function (array $definition) use ($stored): array {
            $resource = $stored->get($definition['key']);
            $busy = $resource ? $this->busyUnits($resource) : 0;
            $capacity = (int) ($resource?->capacity ?? $definition['capacity']);

            return [
                'key' => $definition['key'],
                'name' => $resource?->display_name ?? $definition['name'],
                'type' => $resource?->resource_type ?? $definition['type'],
                'capacity' => $capacity,
                'busy' => $busy,
                'available' => max(0, $capacity - $busy),
                'capabilities' => $resource?->capabilities ?? $definition['capabilities'],
                'source' => $resource?->source ?? 'configured-preview',
            ];
        });

        $extra = $stored->reject(fn (TransportResource $resource) => $definitions->contains('key', $resource->resource_key))
            ->map(function (TransportResource $resource): array {
                $busy = $this->busyUnits($resource);

                return [
                    'key' => $resource->resource_key,
                    'name' => $resource->display_name,
                    'type' => $resource->resource_type,
                    'capacity' => $resource->capacity,
                    'busy' => $busy,
                    'available' => max(0, $resource->capacity - $busy),
                    'capabilities' => $resource->capabilities ?? [],
                    'source' => $resource->source,
                ];
            });

        return $rows->concat($extra)
            ->sortBy(fn (array $row): string => $row['type'].'|'.$row['name'])
            ->values()
            ->all();
    }

    public function syncConfiguredResources(): int
    {
        foreach ($this->configuredResources() as $definition) {
            DB::transaction(function () use ($definition): void {
                $existing = TransportResource::query()
                    ->where('resource_key', $definition['key'])
                    ->lockForUpdate()
                    ->first();
                $busy = $existing ? $this->busyUnits($existing) : 0;
                $this->persistConfiguredResource(
                    $definition,
                    // Never make a live resource mathematically over capacity. A
                    // later sync can converge after the reserved work releases.
                    max(1, (int) $definition['capacity'], $busy),
                );
            });
        }

        return count($this->configuredResources());
    }

    private function lockedRequest(int $requestId): TransportRequest
    {
        return TransportRequest::query()
            ->where('is_deleted', false)
            ->whereKey($requestId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return list<string> */
    private function graphTargets(TransportRequest $request): array
    {
        if ($request->status !== 'escalated') {
            return self::TRANSITIONS[$request->status] ?? [];
        }

        $resume = (string) $request->escalated_from_status;
        if ($resume === '' || ! array_key_exists($resume, self::TRANSITIONS)) {
            return ['canceled', 'failed'];
        }

        return array_values(array_unique(array_merge(
            [$resume],
            array_values(array_diff(self::TRANSITIONS[$resume], ['escalated'])),
            ['canceled', 'failed'],
        )));
    }

    private function assertTransitionAllowed(TransportRequest $request, string $target): void
    {
        if (! in_array($target, $this->graphTargets($request), true)) {
            throw new ConflictHttpException("Illegal transport transition from {$request->status} to {$target}.");
        }
    }

    private function authorizeTransition(User $actor, TransportRequest $request, string $target): void
    {
        if (! $this->actorCanTransition($actor, $request, $target)) {
            throw new AuthorizationException('This actor cannot perform the requested transport transition.');
        }
    }

    private function actorCanTransition(User $actor, TransportRequest $request, string $target): bool
    {
        if ($actor->can('manageTransportDispatch')) {
            return true;
        }
        if (! $actor->can('progressTransportOperations')) {
            return false;
        }
        if ($target === 'assigned') {
            $capabilities = array_values(config('transport.mobile_transporter_capabilities', []));

            return $request->activeAssignment === null
                && $capabilities !== []
                && in_array($request->transport_mode, $capabilities, true);
        }
        if (in_array($target, self::DISPATCH_TARGETS, true)) {
            return false;
        }

        return (int) data_get($request->activeAssignment, 'resource.actor_user_id') === (int) $actor->id;
    }

    private function authorizeDispatcher(User $actor): void
    {
        if (! $actor->can('manageTransportDispatch')) {
            throw new AuthorizationException('Transport dispatch authorization is required.');
        }
    }

    private function authorizeFieldOperator(User $actor): void
    {
        if (! $actor->can('progressTransportOperations') && ! $actor->can('manageTransportDispatch')) {
            throw new AuthorizationException('Transport operator authorization is required.');
        }
    }

    private function assertRequiredReason(string $target, array $payload): void
    {
        if (in_array($target, self::REASON_REQUIRED_TARGETS, true)
            && trim((string) ($payload['reason'] ?? $payload['note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reason' => "A structured reason is required for {$target}.",
            ]);
        }
    }

    private function releaseAssignment(TransportRequest $request, string $terminalStatus, int $actorUserId): void
    {
        TransportAssignment::query()
            ->where('transport_request_id', $request->transport_request_id)
            ->where('status', 'active')
            ->whereNull('released_at')
            ->lockForUpdate()
            ->update([
                'status' => $terminalStatus,
                'released_at' => now(),
                'released_by_user_id' => $actorUserId,
                'updated_at' => now(),
            ]);
    }

    private function resolveConfiguredResource(array $data): TransportResource
    {
        $resourceKey = trim((string) ($data['resource_key'] ?? ''));
        $type = filled($data['assigned_vendor'] ?? null) ? 'vendor' : 'team';
        $name = trim((string) ($data['assigned_vendor'] ?? $data['assigned_team'] ?? ''));
        $definition = collect($this->configuredResources())->first(function (array $candidate) use ($name, $resourceKey): bool {
            return ($resourceKey !== '' && hash_equals($candidate['key'], $resourceKey))
                || ($name !== '' && mb_strtolower($candidate['name']) === mb_strtolower($name));
        });

        if ($definition) {
            $existing = TransportResource::query()->where('resource_key', $definition['key'])->first();
            $busy = $existing ? $this->busyUnits($existing) : 0;

            return $this->persistConfiguredResource(
                $definition,
                max(1, (int) $definition['capacity'], $busy),
            );
        }

        $stored = TransportResource::query()
            ->where('is_active', true)
            ->when($resourceKey !== '', fn ($query) => $query->where('resource_key', $resourceKey))
            ->when($resourceKey === '' && $name !== '', fn ($query) => $query
                ->where('resource_type', $type)
                ->whereRaw('lower(display_name) = ?', [mb_strtolower($name)]))
            ->first();
        if (! $stored) {
            throw ValidationException::withMessages([
                'resource_key' => 'Select an active configured transport resource.',
            ]);
        }

        return $stored;
    }

    private function transporterResource(User $actor): TransportResource
    {
        $resourceKey = "user:{$actor->id}";
        $capabilities = array_values(config('transport.mobile_transporter_capabilities', []));
        if ($capabilities === []) {
            throw ValidationException::withMessages([
                'resource_key' => 'Mobile transporter capabilities are not configured.',
            ]);
        }
        DB::table('prod.transport_resources')->upsert(
            [[
                'resource_uuid' => (string) (TransportResource::query()
                    ->where('resource_key', $resourceKey)
                    ->value('resource_uuid') ?: Str::uuid()),
                'resource_key' => $resourceKey,
                'resource_type' => 'transporter',
                'display_name' => $actor->name ?: "Transporter {$actor->id}",
                'capacity' => 1,
                'actor_user_id' => $actor->id,
                'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
                'source' => 'mobile-identity',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['resource_key'],
            ['resource_type', 'display_name', 'capacity', 'actor_user_id', 'capabilities', 'source', 'is_active', 'updated_at'],
        );

        return TransportResource::query()->where('resource_key', $resourceKey)->firstOrFail();
    }

    /** @param array{key:string,name:string,type:string,capacity:int,capabilities:list<string>} $definition */
    private function persistConfiguredResource(array $definition, int $capacity): TransportResource
    {
        DB::table('prod.transport_resources')->upsert(
            [[
                'resource_uuid' => (string) (TransportResource::query()
                    ->where('resource_key', $definition['key'])
                    ->value('resource_uuid') ?: Str::uuid()),
                'resource_key' => $definition['key'],
                'resource_type' => $definition['type'],
                'display_name' => $definition['name'],
                'capacity' => $capacity,
                'capabilities' => json_encode($definition['capabilities'], JSON_THROW_ON_ERROR),
                'source' => 'configured',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['resource_key'],
            ['resource_type', 'display_name', 'capacity', 'capabilities', 'source', 'is_active', 'updated_at'],
        );

        return TransportResource::query()->where('resource_key', $definition['key'])->firstOrFail();
    }

    private function busyUnits(TransportResource $resource): int
    {
        return (int) TransportAssignment::query()
            ->where('transport_resource_id', $resource->transport_resource_id)
            ->where('status', 'active')
            ->whereNull('released_at')
            ->sum('capacity_units');
    }

    /** @return list<array{key:string,name:string,type:string,capacity:int,capabilities:list<string>}> */
    private function configuredResources(): array
    {
        return collect(config('transport.resources', []))
            ->map(fn (array $resource): array => [
                'key' => (string) $resource['key'],
                'name' => (string) $resource['name'],
                'type' => (string) $resource['type'],
                'capacity' => max(1, (int) $resource['capacity']),
                'capabilities' => array_values($resource['capabilities'] ?? []),
            ])
            ->values()
            ->all();
    }

    private function recordEvent(
        TransportRequest $request,
        string $eventType,
        ?string $from,
        ?string $to,
        array $payload,
        ?int $actorUserId,
    ): TransportEvent {
        return TransportEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'transport_request_id' => $request->transport_request_id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'payload' => $payload,
            'actor_user_id' => $actorUserId,
            'occurred_at' => now(),
        ]);
    }

    private function executeIdempotent(
        ?int $requestId,
        string $commandType,
        string $idempotencyKey,
        array $payload,
        User $actor,
        string $source,
        callable $operation,
    ): TransportRequest {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 200
            || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A 1-200 character Idempotency-Key header using letters, numbers, dot, underscore, colon, or hyphen is required.',
            ]);
        }
        $hash = hash('sha256', json_encode($this->canonicalize([
            'transport_request_id' => $requestId,
            'command_type' => $commandType,
            'actor_user_id' => $actor->id,
            'payload' => $payload,
        ]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $commandType, $hash, $idempotencyKey, $operation, $source): TransportRequest {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["transport:{$idempotencyKey}"]);
            $existing = DB::table('prod.transport_commands')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $hash)) {
                    throw new ConflictHttpException('The idempotency key was already used for a different transport command.');
                }
                $response = is_string($existing->response_payload)
                    ? json_decode($existing->response_payload, true, 512, JSON_THROW_ON_ERROR)
                    : (array) $existing->response_payload;

                return $this->restoreCommandResponse($response);
            }

            /** @var TransportRequest $result */
            $result = $operation();
            $result->loadMissing('activeAssignment.resource', 'handoffEvidence');
            DB::table('prod.transport_commands')->insert([
                'command_uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'transport_request_id' => $result->transport_request_id,
                'command_type' => $commandType,
                'request_hash' => $hash,
                'response_payload' => json_encode([
                    'transport_request_id' => $result->transport_request_id,
                    'status' => $result->status,
                    'lifecycle_version' => $result->lifecycle_version,
                    'request' => $result->toArray(),
                ], JSON_THROW_ON_ERROR),
                'actor_user_id' => $actor->id,
                'source' => $source,
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    /** @param array<string,mixed> $response */
    private function restoreCommandResponse(array $response): TransportRequest
    {
        if (! is_array($response['request'] ?? null)) {
            return TransportRequest::query()
                ->with('activeAssignment.resource', 'handoffEvidence')
                ->findOrFail((int) $response['transport_request_id']);
        }

        $attributes = $response['request'];
        $assignmentData = $attributes['active_assignment'] ?? null;
        $handoffData = $attributes['handoff_evidence'] ?? null;
        unset($attributes['active_assignment'], $attributes['handoff_evidence']);

        $request = new TransportRequest;
        $request->setRawAttributes($this->rawSnapshotAttributes($request, $attributes), true);
        $request->exists = true;

        $assignment = null;
        if (is_array($assignmentData)) {
            $resourceData = $assignmentData['resource'] ?? null;
            unset($assignmentData['resource']);
            $assignment = new TransportAssignment;
            $assignment->setRawAttributes($this->rawSnapshotAttributes($assignment, $assignmentData), true);
            $assignment->exists = true;

            $resource = null;
            if (is_array($resourceData)) {
                $resource = new TransportResource;
                $resource->setRawAttributes($this->rawSnapshotAttributes($resource, $resourceData), true);
                $resource->exists = true;
            }
            $assignment->setRelation('resource', $resource);
        }
        $request->setRelation('activeAssignment', $assignment);

        $handoff = null;
        if (is_array($handoffData)) {
            $handoff = new TransportHandoffEvidence;
            $handoff->setRawAttributes($this->rawSnapshotAttributes($handoff, $handoffData), true);
            $handoff->exists = true;
        }
        $request->setRelation('handoffEvidence', $handoff);

        return $request;
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function rawSnapshotAttributes(Model $model, array $attributes): array
    {
        foreach ($model->getCasts() as $key => $cast) {
            $cast = explode(':', (string) $cast, 2)[0];
            if (array_key_exists($key, $attributes)
                && is_array($attributes[$key])
                && in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
                $attributes[$key] = json_encode($attributes[$key], JSON_THROW_ON_ERROR);
            }
        }

        return $attributes;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
