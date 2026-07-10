<?php

namespace App\Models\Staffing;

use App\Models\Org\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffShiftAssignment extends Model
{
    protected $table = 'prod.staff_shift_assignments';

    protected $primaryKey = 'staff_shift_assignment_id';

    protected $fillable = [
        'shift_assignment_uuid', 'staffing_request_id', 'staff_member_id',
        'unit_id', 'facility_key', 'service_line_code', 'role_code',
        'starts_at', 'ends_at', 'timezone', 'status', 'validation_snapshot',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected $casts = [
        'staffing_request_id' => 'integer',
        'staff_member_id' => 'integer',
        'unit_id' => 'integer',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'validation_snapshot' => 'array',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(StaffingRequest::class, 'staffing_request_id', 'staffing_request_id');
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id', 'staff_member_id');
    }
}
