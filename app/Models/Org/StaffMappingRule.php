<?php

namespace App\Models\Org;

use App\Models\Reference\ServiceLine;
use App\Models\Reference\StaffRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7: a deterministic crosswalk rule the resolver applies (learned from
 * reviewer decisions). Matches a source field (cost_center, department, specialty,
 * job_code, job_title, home_unit) to a target service line + role + unit hint.
 * Lower priority runs first.
 */
class StaffMappingRule extends Model
{
    protected $table = 'hosp_org.staff_mapping_rules';

    protected $primaryKey = 'staff_mapping_rule_id';

    protected $fillable = [
        'staffing_source_id',
        'match_field',
        'match_operator',
        'match_value',
        'target_service_line_code',
        'target_role_code',
        'target_unit_hint',
        'priority',
        'confidence',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'staffing_source_id' => 'integer',
        'priority' => 'integer',
        'confidence' => 'decimal:4',
        'is_active' => 'boolean',
        'created_by' => 'integer',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(StaffingSource::class, 'staffing_source_id', 'staffing_source_id');
    }

    public function targetServiceLine(): BelongsTo
    {
        return $this->belongsTo(ServiceLine::class, 'target_service_line_code', 'service_line_code');
    }

    public function targetRole(): BelongsTo
    {
        return $this->belongsTo(StaffRole::class, 'target_role_code', 'role_code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
