<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalEventTarget extends Model
{
    public $timestamps = false;

    protected $table = 'ops.operational_event_targets';

    protected $primaryKey = 'operational_event_target_id';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(OperationalEvent::class, 'operational_event_id', 'operational_event_id');
    }
}
