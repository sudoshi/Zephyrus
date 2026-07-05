<?php

namespace App\Models\Reference;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Layer 2 registry row: one canonical enterprise service line.
 *
 * Authored in config/hospital/service-lines.php and projected into
 * hosp_ref.service_lines by App\Services\Deployment\ServiceLineRegistrar.
 * Read-optimized; array-column writes should go through the registrar.
 */
class ServiceLine extends Model
{
    protected $table = 'hosp_ref.service_lines';

    protected $primaryKey = 'service_line_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'service_line_code',
        'display_name',
        'clinical_domain',
        'adult_or_pediatric',
        'care_setting_default',
        'hcup_grouping',
        'requires_24_7',
        'requires_inpatient_beds',
        'requires_procedure_platform',
        'requires_imaging',
        'requires_lab',
        'requires_pharmacy',
        'requires_transport',
        'requires_transfer_agreements',
        'certification_or_designation',
        'default_location_roles',
        'default_workflow',
        'aliases',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'requires_24_7' => 'boolean',
        'requires_inpatient_beds' => 'boolean',
        'requires_procedure_platform' => 'boolean',
        'requires_imaging' => 'boolean',
        'requires_lab' => 'boolean',
        'requires_pharmacy' => 'boolean',
        'requires_transport' => 'boolean',
        'requires_transfer_agreements' => 'boolean',
        'certification_or_designation' => PgTextArray::class,
        'default_location_roles' => PgTextArray::class,
        'aliases' => PgTextArray::class,
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'service_line_code', 'service_line_code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
