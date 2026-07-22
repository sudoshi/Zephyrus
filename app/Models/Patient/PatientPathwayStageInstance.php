<?php

namespace App\Models\Patient;

use App\Models\CarePathways\PathwayStageDefinition;
use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientPathwayStageInstance extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'stage_instance_uuid';

    protected $table = 'patient_experience.pathway_stage_instances';

    protected $primaryKey = 'pathway_stage_instance_id';

    protected $fillable = [
        'stage_instance_uuid',
        'pathway_instance_id',
        'stage_definition_id',
        'instantiated_at',
    ];

    protected $hidden = ['pathway_instance_id', 'stage_definition_id'];

    protected $casts = [
        'pathway_instance_id' => 'integer',
        'stage_definition_id' => 'integer',
        'instantiated_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function pathwayInstance(): BelongsTo
    {
        return $this->belongsTo(PatientPathwayInstance::class, 'pathway_instance_id', 'pathway_instance_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(PathwayStageDefinition::class, 'stage_definition_id', 'stage_definition_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(PatientPathwayStageStatusEvent::class, 'pathway_stage_instance_id', 'pathway_stage_instance_id');
    }
}
