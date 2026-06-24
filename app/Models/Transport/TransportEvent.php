<?php

namespace App\Models\Transport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportEvent extends Model
{
    protected $table = 'prod.transport_events';

    protected $primaryKey = 'transport_event_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_uuid',
        'transport_request_id',
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
        return $this->belongsTo(TransportRequest::class, 'transport_request_id', 'transport_request_id');
    }
}
