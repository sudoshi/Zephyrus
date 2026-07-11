<?php

namespace App\Models\Rounds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Append-only rounds audit stream. Also the idempotency ledger: commands
 * write their event inside the command transaction, and the partial unique
 * index on idempotency_key turns a concurrent replay into a database
 * conflict instead of a double execution.
 *
 * metadata must stay PHI-safe: opaque refs and reason codes only.
 */
class RoundEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rounds.events';

    protected $primaryKey = 'event_id';

    protected $fillable = [
        'event_uuid', 'aggregate_type', 'aggregate_id', 'aggregate_uuid',
        'aggregate_version', 'actor_user_id', 'actor_type', 'event_type',
        'metadata', 'correlation_key', 'idempotency_key', 'occurred_at',
    ];

    protected $casts = [
        'aggregate_id' => 'integer',
        'aggregate_version' => 'integer',
        'actor_user_id' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** @param array<string, mixed> $metadata */
    public static function record(
        string $aggregateType,
        int $aggregateId,
        ?string $aggregateUuid,
        ?int $aggregateVersion,
        ?int $actorUserId,
        string $eventType,
        array $metadata = [],
        ?string $idempotencyKey = null,
        string $actorType = 'user',
    ): self {
        return self::create([
            'event_uuid' => (string) Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'aggregate_uuid' => $aggregateUuid,
            'aggregate_version' => $aggregateVersion,
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorType,
            'event_type' => $eventType,
            'metadata' => $metadata,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
    }
}
