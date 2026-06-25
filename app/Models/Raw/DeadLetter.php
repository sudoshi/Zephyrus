<?php

namespace App\Models\Raw;

use App\Models\Integration\Source;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeadLetter extends Model
{
    protected $table = 'raw.dead_letters';

    protected $primaryKey = 'dead_letter_id';

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'replayed_at' => 'datetime',
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
