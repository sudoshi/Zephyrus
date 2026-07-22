<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientContentAction extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'action_uuid';

    protected $table = 'patient_experience.content_actions';

    protected $primaryKey = 'content_action_id';

    protected $attributes = ['actor_type' => 'system'];

    protected $fillable = [
        'action_uuid',
        'target_projection_id',
        'replacement_projection_id',
        'release_policy_version_id',
        'action_type',
        'reason_code',
        'actor_type',
        'actor_ref',
        'effective_at',
    ];

    protected $hidden = ['actor_ref'];

    protected $casts = [
        'target_projection_id' => 'integer',
        'replacement_projection_id' => 'integer',
        'release_policy_version_id' => 'integer',
        'effective_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PatientContentAction $action): void {
            $target = PatientEncounterProjection::query()->find($action->target_projection_id);
            if ($target === null || $target->contentActions()->exists()) {
                throw new \InvalidArgumentException('patient_content_action_target_invalid');
            }

            if ($action->effective_at === null
                || ($target->released_at !== null && $action->effective_at->lt($target->released_at))) {
                throw new \InvalidArgumentException('patient_content_action_effective_time_invalid');
            }

            if ($action->action_type === 'correction') {
                $replacement = PatientEncounterProjection::query()->find($action->replacement_projection_id);
                if ($replacement === null
                    || (int) $replacement->access_grant_id !== (int) $target->access_grant_id
                    || $replacement->projection_kind !== $target->projection_kind
                    || (int) $replacement->supersedes_projection_id !== (int) $target->getKey()
                    || (int) $replacement->release_policy_version_id !== (int) $action->release_policy_version_id
                    || $replacement->release_state !== 'released') {
                    throw new \InvalidArgumentException('patient_content_correction_replacement_invalid');
                }
            }
        });
    }

    public function targetProjection(): BelongsTo
    {
        return $this->belongsTo(
            PatientEncounterProjection::class,
            'target_projection_id',
            'encounter_projection_id',
        );
    }

    public function replacementProjection(): BelongsTo
    {
        return $this->belongsTo(
            PatientEncounterProjection::class,
            'replacement_projection_id',
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
}
