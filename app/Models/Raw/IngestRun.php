<?php

namespace App\Models\Raw;

use App\Models\Integration\Source;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestRun extends Model
{
    protected $table = 'raw.ingest_runs';

    protected $primaryKey = 'ingest_run_id';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'messages_received' => 'integer',
        'messages_succeeded' => 'integer',
        'messages_failed' => 'integer',
        'messages_skipped' => 'integer',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id', 'source_id');
    }

    public function inboundMessages(): HasMany
    {
        return $this->hasMany(InboundMessage::class, 'ingest_run_id', 'ingest_run_id');
    }
}
