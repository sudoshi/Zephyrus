<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientPathwayProjectionReleaseExecution extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'release_execution_uuid';

    protected $table = 'patient_experience.pathway_projection_release_executions';

    protected $primaryKey = 'pathway_projection_release_execution_id';

    protected $fillable = [
        'release_execution_uuid',
        'pathway_projection_review_id',
        'released_projection_id',
        'release_manager_actor_digest',
        'release_digest',
        'released_at',
    ];

    protected $hidden = ['release_manager_actor_digest', 'release_digest'];

    protected $casts = [
        'pathway_projection_review_id' => 'integer',
        'released_projection_id' => 'integer',
        'released_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(
            PatientPathwayProjectionReview::class,
            'pathway_projection_review_id',
            'pathway_projection_review_id',
        );
    }

    public function releasedProjection(): BelongsTo
    {
        return $this->belongsTo(
            PatientEncounterProjection::class,
            'released_projection_id',
            'encounter_projection_id',
        );
    }
}
