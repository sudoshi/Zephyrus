<?php

namespace App\Models\Org;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7: one wizard / import batch. Owns the lifecycle
 * staged -> resolved -> in_review -> committed, and records bucket counts.
 * Dry-run by default; commit is a separate, explicit step.
 */
class StaffImportRun extends Model
{
    protected $table = 'hosp_org.staff_import_runs';

    protected $primaryKey = 'staff_import_run_id';

    protected $fillable = [
        'staffing_source_id',
        'status',
        'mapping_snapshot',
        'counts',
        'staged',
        'dry_run',
        'initiated_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'staffing_source_id' => 'integer',
        'mapping_snapshot' => 'array',
        'counts' => 'array',
        'staged' => 'array',
        'dry_run' => 'boolean',
        'initiated_by' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(StaffingSource::class, 'staffing_source_id', 'staffing_source_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StaffMappingReview::class, 'staff_import_run_id', 'staff_import_run_id');
    }
}
