<?php

namespace App\Models\Staffing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffingPlan extends Model
{
    protected $table = 'prod.staffing_plans';

    protected $primaryKey = 'staffing_plan_id';

    protected $fillable = [
        'plan_uuid',
        'unit_id',
        'unit_label',
        'role',
        'shift_date',
        'shift',
        'required_count',
        'scheduled_count',
        'actual_count',
        'minimum_safe_count',
        'census',
        'ratio_target',
        'status',
        'notes',
        'constraints',
        'metadata',
        'created_by_user_id',
        'updated_by_user_id',
        'is_deleted',
    ];

    protected $casts = [
        'unit_id' => 'integer',
        'shift_date' => 'date',
        'required_count' => 'integer',
        'scheduled_count' => 'integer',
        'actual_count' => 'integer',
        'minimum_safe_count' => 'integer',
        'census' => 'integer',
        'ratio_target' => 'float',
        'constraints' => 'array',
        'metadata' => 'array',
        'is_deleted' => 'boolean',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(StaffingRequest::class, 'staffing_plan_id', 'staffing_plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    /** Coverage gap = required minus the better of scheduled/actual on the floor. */
    public function gap(): int
    {
        return max(0, $this->required_count - max($this->scheduled_count, $this->actual_count));
    }

    public function belowMinimumSafe(): bool
    {
        return max($this->scheduled_count, $this->actual_count) < $this->minimum_safe_count;
    }
}
