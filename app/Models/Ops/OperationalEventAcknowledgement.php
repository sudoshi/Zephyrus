<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalEventAcknowledgement extends Model
{
    public $timestamps = false;

    protected $table = 'ops.operational_event_acknowledgements';

    protected $primaryKey = 'operational_event_acknowledgement_id';

    protected $guarded = [];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(OperationalEvent::class, 'operational_event_id', 'operational_event_id');
    }
}
