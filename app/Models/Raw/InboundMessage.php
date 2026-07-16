<?php

namespace App\Models\Raw;

use App\Models\Integration\Source;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundMessage extends Model
{
    protected $table = 'raw.inbound_messages';

    protected $primaryKey = 'inbound_message_id';

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected function payload(): Attribute
    {
        return Attribute::make(get: fn (mixed $value, array $attributes): ?array => app(
            \App\Security\ClinicalPayloads\ClinicalPayloadHydrator::class,
        )->optional(
            isset($attributes['payload_object_id']) ? (int) $attributes['payload_object_id'] : null,
            (int) $attributes['source_id'],
            isset($attributes['payload_object_id']) && $this->objectKind((int) $attributes['payload_object_id']) === 'fhir_resource'
                ? 'fhir_resource'
                : 'raw_message',
            $value,
        ));
    }

    protected function normalizedPayload(): Attribute
    {
        return Attribute::make(get: fn (mixed $value, array $attributes): ?array => app(
            \App\Security\ClinicalPayloads\ClinicalPayloadHydrator::class,
        )->optional(
            isset($attributes['normalized_payload_object_id']) ? (int) $attributes['normalized_payload_object_id'] : null,
            (int) $attributes['source_id'],
            'normalized_message',
            $value,
        ));
    }

    private function objectKind(int $payloadObjectId): ?string
    {
        return \Illuminate\Support\Facades\DB::table('raw.payload_objects')
            ->where('payload_object_id', $payloadObjectId)
            ->value('payload_kind');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function ingestRun(): BelongsTo
    {
        return $this->belongsTo(IngestRun::class, 'ingest_run_id', 'ingest_run_id');
    }

    public function deadLetters(): HasMany
    {
        return $this->hasMany(DeadLetter::class, 'inbound_message_id', 'inbound_message_id');
    }
}
