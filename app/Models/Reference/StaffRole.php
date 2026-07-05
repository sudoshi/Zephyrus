<?php

namespace App\Models\Reference;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7 role taxonomy row: one operational staff role (intensivist, charge_nurse,
 * transport_tech, ...). Service-line-agnostic — the service line + unit live on the
 * staff_assignment. Authored in config/hospital/staff-roles.php and projected into
 * hosp_ref.staff_roles by App\Services\Staffing\StaffRoleRegistrar.
 *
 * is_regulated marks attendings in regulated specialties (trauma/transplant/burn/
 * neuro/perinatal/neonatal) that the resolver may NEVER auto-approve.
 */
class StaffRole extends Model
{
    protected $table = 'hosp_ref.staff_roles';

    protected $primaryKey = 'role_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'role_code',
        'display_name',
        'role_category',
        'is_provider',
        'is_nursing',
        'is_clinical',
        'is_regulated',
        'default_workflow',
        'default_app_permissions',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'is_provider' => 'boolean',
        'is_nursing' => 'boolean',
        'is_clinical' => 'boolean',
        'is_regulated' => 'boolean',
        'default_app_permissions' => PgTextArray::class,
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function scopeRegulated(Builder $query): Builder
    {
        return $query->where('is_regulated', true);
    }
}
