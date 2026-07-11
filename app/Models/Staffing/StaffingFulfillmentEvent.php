<?php

namespace App\Models\Staffing;

use Illuminate\Database\Eloquent\Model;

class StaffingFulfillmentEvent extends Model
{
    public $timestamps = false;

    protected $table = 'prod.staffing_fulfillment_events';

    protected $primaryKey = 'staffing_fulfillment_event_id';

    protected $fillable = [
        'event_uuid', 'fulfillment_uuid', 'staffing_request_id', 'staff_member_id',
        'event_type', 'from_status', 'to_status', 'payload', 'actor_user_id',
        'occurred_at', 'created_at',
    ];

    protected $casts = [
        'staffing_request_id' => 'integer',
        'staff_member_id' => 'integer',
        'payload' => 'array',
        'actor_user_id' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
