<?php

namespace App\Models\Staffing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffingEvent extends Model
{
    public $timestamps = false;

    protected $table = 'prod.staffing_events';

    protected $primaryKey = 'staffing_event_id';

    protected $fillable = [
        'event_uuid',
        'staffing_request_id',
        'event_type',
        'from_status',
        'to_status',
        'payload',
        'source',
        'actor_user_id',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'staffing_request_id' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffingRequest::class, 'staffing_request_id', 'staffing_request_id');
    }
}
