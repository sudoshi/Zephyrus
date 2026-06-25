<?php

namespace App\Models\Raw;

use App\Models\Integration\Source;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundMessage extends Model
{
    protected $table = 'raw.inbound_messages';

    protected $primaryKey = 'inbound_message_id';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'normalized_payload' => 'array',
        'received_at' => 'datetime',
        'metadata' => 'array',
    ];

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
