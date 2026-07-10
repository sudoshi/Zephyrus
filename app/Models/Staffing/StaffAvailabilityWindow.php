<?php

namespace App\Models\Staffing;

use App\Models\Org\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAvailabilityWindow extends Model
{
    protected $table = 'prod.staff_availability_windows';

    protected $primaryKey = 'staff_availability_window_id';

    protected $fillable = [
        'availability_uuid', 'external_key', 'staff_member_id', 'window_type',
        'starts_at', 'ends_at', 'timezone', 'source', 'priority', 'metadata',
    ];

    protected $casts = [
        'staff_member_id' => 'integer',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'priority' => 'integer',
        'metadata' => 'array',
    ];

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id', 'staff_member_id');
    }
}
