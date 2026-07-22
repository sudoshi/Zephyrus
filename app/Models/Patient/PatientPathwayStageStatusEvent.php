<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientPathwayStageStatusEvent extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'stage_status_event_uuid';

    protected $table = 'patient_experience.pathway_stage_status_events';

    protected $primaryKey = 'pathway_stage_status_event_id';

    protected $fillable = [
        'stage_status_event_uuid',
        'pathway_stage_instance_id',
        'status',
        'source_event_digest',
        'source_observed_at',
    ];

    protected $hidden = ['pathway_stage_instance_id', 'source_event_digest'];

    protected $casts = [
        'pathway_stage_instance_id' => 'integer',
        'source_observed_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function stageInstance(): BelongsTo
    {
        return $this->belongsTo(PatientPathwayStageInstance::class, 'pathway_stage_instance_id', 'pathway_stage_instance_id');
    }
}
