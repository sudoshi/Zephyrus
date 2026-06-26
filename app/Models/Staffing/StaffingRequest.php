<?php

namespace App\Models\Staffing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffingRequest extends Model
{
    protected $table = 'prod.staffing_requests';

    protected $primaryKey = 'staffing_request_id';

    protected $fillable = [
        'request_uuid',
        'unit_id',
        'staffing_plan_id',
        'unit_label',
        'role',
        'shift_date',
        'shift',
        'request_type',
        'priority',
        'status',
        'headcount_needed',
        'hours_needed',
        'requested_by',
        'needed_by',
        'assigned_at',
        'filled_at',
        'completed_at',
        'assigned_source',
        'assigned_staff_ref',
        'owner_name',
        'risk_flags',
        'resolution_payload',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
        'is_deleted',
    ];

    protected $casts = [
        'unit_id' => 'integer',
        'staffing_plan_id' => 'integer',
        'shift_date' => 'date',
        'headcount_needed' => 'integer',
        'hours_needed' => 'float',
        'needed_by' => 'datetime',
        'assigned_at' => 'datetime',
        'filled_at' => 'datetime',
        'completed_at' => 'datetime',
        'risk_flags' => 'array',
        'resolution_payload' => 'array',
        'metadata' => 'array',
        'is_deleted' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(StaffingEvent::class, 'staffing_request_id', 'staffing_request_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StaffingPlan::class, 'staffing_plan_id', 'staffing_plan_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->where('is_deleted', false)
            ->whereNotIn('status', ['completed', 'canceled']);
    }

    public function scopeForRole($query, ?string $role)
    {
        return $role ? $query->where('role', $role) : $query;
    }
}
