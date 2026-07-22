<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceStatusEvent extends Model
{
    protected $table = 'care_pathways.source_status_events';

    protected $primaryKey = 'source_status_event_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'observed_at' => 'immutable_datetime',
        'effective_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(PathwaySource::class, 'source_id');
    }
}
