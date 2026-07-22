<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientPathwayMilestoneStatusEvent extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'milestone_status_event_uuid';

    protected $table = 'patient_experience.pathway_milestone_status_events';

    protected $primaryKey = 'pathway_milestone_status_event_id';

    protected $fillable = [
        'milestone_status_event_uuid',
        'pathway_milestone_instance_id',
        'status',
        'source_event_digest',
        'source_observed_at',
    ];

    protected $hidden = ['pathway_milestone_instance_id', 'source_event_digest'];

    protected $casts = [
        'pathway_milestone_instance_id' => 'integer',
        'source_observed_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function milestoneInstance(): BelongsTo
    {
        return $this->belongsTo(PatientPathwayMilestoneInstance::class, 'pathway_milestone_instance_id', 'pathway_milestone_instance_id');
    }
}
