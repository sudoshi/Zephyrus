<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalEventEntity extends Model
{
    public $timestamps = false;

    protected $table = 'ops.operational_event_entities';

    protected $primaryKey = 'operational_event_entity_id';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(OperationalEvent::class, 'operational_event_id', 'operational_event_id');
    }
}
