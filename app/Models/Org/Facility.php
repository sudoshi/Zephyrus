<?php

namespace App\Models\Org;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Layer 1: a physical facility with its idn_role and regulated designations.
 * Linked to the CAD/RTDC world by cad_facility_code (e.g. 'ZEPHYRUS-500' for Summit).
 */
class Facility extends Model
{
    protected $table = 'hosp_org.facilities';

    protected $primaryKey = 'facility_id';

    protected $fillable = [
        'organization_id',
        'market_id',
        'facility_key',
        'facility_name',
        'short_name',
        'parent_system',
        'market',
        'region',
        'state',
        'county',
        'lat',
        'lng',
        'idn_role',
        'campus_type',
        'license_type',
        'teaching_status',
        'licensed_beds',
        'trauma_level_adult',
        'trauma_level_pediatric',
        'stroke_level',
        'maternal_level',
        'neonatal_level',
        'burn_center_status',
        'transplant_center_status',
        'transplant_programs',
        'pediatric_capability',
        'behavioral_health_capability',
        'ambulatory_surgery_capability',
        'home_hospital_capability',
        'cad_facility_code',
        'review_status',
        'source_evidence',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'market_id' => 'integer',
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'licensed_beds' => 'integer',
        'transplant_programs' => PgTextArray::class,
        'source_evidence' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id', 'market_id');
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(FacilityServiceCapability::class, 'facility_id', 'facility_id');
    }

    public function outboundTransfers(): HasMany
    {
        return $this->hasMany(TransferRelationship::class, 'source_facility_id', 'facility_id');
    }

    public function inboundTransfers(): HasMany
    {
        return $this->hasMany(TransferRelationship::class, 'destination_facility_id', 'facility_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
