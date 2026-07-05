<?php

namespace App\Models\Org;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7: a configured external staffing connector (HRIS / scheduling / identity /
 * EHR master / CSV upload). Transport + secrets live in integration.sources; this row
 * stores only a soft integration_source_id reference plus a reusable field-mapping
 * template. Never stores connector secrets.
 */
class StaffingSource extends Model
{
    protected $table = 'hosp_org.staffing_sources';

    protected $primaryKey = 'staffing_source_id';

    protected $fillable = [
        'organization_id',
        'integration_source_id',
        'source_key',
        'display_name',
        'connector_type',
        'transport',
        'mapping_template',
        'sync_schedule',
        'is_active',
        'last_synced_at',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'integration_source_id' => 'integer',
        'mapping_template' => 'array',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    public function importRuns(): HasMany
    {
        return $this->hasMany(StaffImportRun::class, 'staffing_source_id', 'staffing_source_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(StaffMappingRule::class, 'staffing_source_id', 'staffing_source_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
