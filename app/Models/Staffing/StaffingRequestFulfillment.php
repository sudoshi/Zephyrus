<?php

namespace App\Models\Staffing;

use App\Models\Org\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffingRequestFulfillment extends Model
{
    protected $table = 'prod.staffing_request_fulfillments';

    protected $primaryKey = 'staffing_request_fulfillment_id';

    protected $fillable = [
        'fulfillment_uuid', 'staffing_request_id', 'staff_shift_assignment_id',
        'staff_member_id', 'status', 'source', 'version', 'offered_at',
        'accepted_at', 'filled_at', 'released_at', 'canceled_at',
        'last_actor_user_id', 'metadata',
    ];

    protected $casts = [
        'staffing_request_id' => 'integer',
        'staff_shift_assignment_id' => 'integer',
        'staff_member_id' => 'integer',
        'version' => 'integer',
        'offered_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'filled_at' => 'immutable_datetime',
        'released_at' => 'immutable_datetime',
        'canceled_at' => 'immutable_datetime',
        'last_actor_user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffingRequest::class, 'staffing_request_id', 'staffing_request_id');
    }

    public function shiftAssignment(): BelongsTo
    {
        return $this->belongsTo(StaffShiftAssignment::class, 'staff_shift_assignment_id', 'staff_shift_assignment_id');
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id', 'staff_member_id');
    }
}
