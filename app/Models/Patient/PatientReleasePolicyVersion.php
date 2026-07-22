<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientReleasePolicyVersion extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'policy_uuid';

    protected $table = 'patient_experience.release_policy_versions';

    protected $primaryKey = 'release_policy_version_id';

    protected $attributes = [
        'status' => 'draft',
        'rules' => '{}',
    ];

    protected $fillable = [
        'policy_uuid',
        'version',
        'status',
        'disclosure_matrix_version',
        'content_contract_version',
        'rules',
        'approved_by_actor_ref',
        'approved_at',
        'effective_from',
        'effective_to',
    ];

    protected $hidden = [
        'approved_by_actor_ref',
        'rules',
    ];

    protected $casts = [
        'rules' => 'array',
        'approved_at' => 'immutable_datetime',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->whereNotNull('approved_at')
            ->where('effective_from', '<=', now())
            ->where(function (Builder $window): void {
                $window->whereNull('effective_to')->orWhere('effective_to', '>', now());
            });
    }

    public function projections(): HasMany
    {
        return $this->hasMany(
            PatientEncounterProjection::class,
            'release_policy_version_id',
            'release_policy_version_id',
        );
    }
}
