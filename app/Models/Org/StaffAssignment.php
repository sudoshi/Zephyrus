<?php

namespace App\Models\Org;

use App\Models\Reference\ServiceLine;
use App\Models\Reference\StaffRole;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 core output: one operational membership — staff x facility x service line
 * x role x (optional) unit — with confidence, resolution_source, evidence, and a
 * review_status. Multi-membership is normal; exactly one primary_flag per person
 * (enforced by uq_staff_one_primary). Effective-dated and soft-deactivated
 * (is_active), never hard-deleted.
 */
class StaffAssignment extends Model
{
    protected $table = 'hosp_org.staff_assignments';

    protected $primaryKey = 'staff_assignment_id';

    protected $fillable = [
        'staff_member_id',
        'facility_key',
        'service_line_code',
        'role_code',
        'program_code',
        'unit_id',
        'primary_flag',
        'coverage_model',
        'fte',
        'confidence',
        'resolution_source',
        'review_status',
        'evidence',
        'effective_start',
        'effective_end',
        'is_active',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'staff_member_id' => 'integer',
        'unit_id' => 'integer',
        'primary_flag' => 'boolean',
        'fte' => 'decimal:2',
        'confidence' => 'decimal:4',
        'evidence' => 'array',
        'effective_start' => 'date',
        'effective_end' => 'date',
        'is_active' => 'boolean',
        'decided_by' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id', 'staff_member_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(StaffRole::class, 'role_code', 'role_code');
    }

    public function serviceLine(): BelongsTo
    {
        return $this->belongsTo(ServiceLine::class, 'service_line_code', 'service_line_code');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
