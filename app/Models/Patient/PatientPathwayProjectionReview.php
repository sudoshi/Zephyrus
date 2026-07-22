<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientPathwayProjectionReview extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'review_uuid';

    protected $table = 'patient_experience.pathway_projection_reviews';

    protected $primaryKey = 'pathway_projection_review_id';

    protected $fillable = [
        'review_uuid',
        'draft_projection_id',
        'release_policy_version_id',
        'reviewer_user_id',
        'decision',
        'reason_code',
        'review_digest',
        'reviewed_at',
    ];

    protected $hidden = ['reviewer_user_id', 'review_digest'];

    protected $casts = [
        'draft_projection_id' => 'integer',
        'release_policy_version_id' => 'integer',
        'reviewer_user_id' => 'integer',
        'reviewed_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function draftProjection(): BelongsTo
    {
        return $this->belongsTo(
            PatientEncounterProjection::class,
            'draft_projection_id',
            'encounter_projection_id',
        );
    }

    public function releasePolicyVersion(): BelongsTo
    {
        return $this->belongsTo(
            PatientReleasePolicyVersion::class,
            'release_policy_version_id',
            'release_policy_version_id',
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
