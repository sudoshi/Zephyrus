<?php

namespace App\Models\Integration;

use App\Models\Raw\InboundMessage;
use App\Models\Raw\IngestRun;
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
        'payload' => 'array',
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

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'inbound_message_id', 'inbound_message_id');
    }
}
