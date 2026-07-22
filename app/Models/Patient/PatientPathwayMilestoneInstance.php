<?php

namespace App\Models\Patient;

use App\Models\CarePathways\MilestoneDefinition;
use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientPathwayMilestoneInstance extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'milestone_instance_uuid';

    protected $table = 'patient_experience.pathway_milestone_instances';

    protected $primaryKey = 'pathway_milestone_instance_id';

    protected $fillable = [
        'milestone_instance_uuid',
        'pathway_instance_id',
        'milestone_definition_id',
        'instantiated_at',
    ];

    protected $hidden = ['pathway_instance_id', 'milestone_definition_id'];

    protected $casts = [
        'pathway_instance_id' => 'integer',
        'milestone_definition_id' => 'integer',
        'instantiated_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function pathwayInstance(): BelongsTo
    {
        return $this->belongsTo(PatientPathwayInstance::class, 'pathway_instance_id', 'pathway_instance_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(MilestoneDefinition::class, 'milestone_definition_id', 'milestone_definition_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(PatientPathwayMilestoneStatusEvent::class, 'pathway_milestone_instance_id', 'pathway_milestone_instance_id');
    }
}
