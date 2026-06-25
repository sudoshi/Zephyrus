<?php

namespace App\Models\Integration;

use App\Models\Raw\InboundMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvenanceRecord extends Model
{
    protected $table = 'integration.provenance_records';

    protected $primaryKey = 'provenance_record_id';

    protected $guarded = [];

    protected $casts = [
        'lineage' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'inbound_message_id', 'inbound_message_id');
    }

    public function canonicalEvent(): BelongsTo
    {
        return $this->belongsTo(CanonicalEventRecord::class, 'canonical_event_id', 'canonical_event_id');
    }
}
