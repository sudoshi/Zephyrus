<?php

namespace App\Models\Evs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvsEvent extends Model
{
    protected $table = 'prod.evs_events';

    protected $primaryKey = 'evs_event_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_uuid',
        'evs_request_id',
        'event_type',
        'from_status',
        'to_status',
        'payload',
        'source',
        'actor_user_id',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(EvsRequest::class, 'evs_request_id', 'evs_request_id');
    }
}
