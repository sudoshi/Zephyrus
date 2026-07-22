<?php

namespace App\Models\Patient;

use App\Models\CarePathways\PathwayVersion;
use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable encounter-scoped assignment to one exact care-pathway version.
 * It contains only digested source linkage and is not patient-visible itself.
 */
class PatientPathwayInstance extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'pathway_instance_uuid';

    protected $table = 'patient_experience.pathway_instances';

    protected $primaryKey = 'pathway_instance_id';

    protected $fillable = [
        'pathway_instance_uuid',
        'access_grant_id',
        'pathway_version_id',
        'source_system_key',
        'source_assignment_digest',
        'source_observed_at',
        'instantiated_at',
    ];

    protected $hidden = [
        'access_grant_id',
        'pathway_version_id',
        'source_assignment_digest',
    ];

    protected $casts = [
        'access_grant_id' => 'integer',
        'pathway_version_id' => 'integer',
        'source_observed_at' => 'immutable_datetime',
        'instantiated_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(PatientEncounterAccessGrant::class, 'access_grant_id', 'access_grant_id');
    }

    public function pathwayVersion(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id', 'pathway_version_id');
    }

    public function stageInstances(): HasMany
    {
        return $this->hasMany(PatientPathwayStageInstance::class, 'pathway_instance_id', 'pathway_instance_id');
    }

    public function milestoneInstances(): HasMany
    {
        return $this->hasMany(PatientPathwayMilestoneInstance::class, 'pathway_instance_id', 'pathway_instance_id');
    }
}
