<?php

namespace App\Models\Integration;

use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanonicalEventRecord extends Model
{
    protected $table = 'integration.canonical_events';

    protected $primaryKey = 'canonical_event_id';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'projected_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected function payload(): Attribute
    {
        return Attribute::make(get: fn (mixed $value, array $attributes): array => app(
            \App\Security\ClinicalPayloads\ClinicalPayloadHydrator::class,
        )->required(
            isset($attributes['payload_object_id']) ? (int) $attributes['payload_object_id'] : null,
            (int) $attributes['source_id'],
            'canonical_event',
            $value,
        ));
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(IngestRun::class, 'ingest_run_id', 'ingest_run_id');
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'inbound_message_id', 'inbound_message_id');
    }
}
