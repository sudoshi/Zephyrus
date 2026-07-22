<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Content-free immutable pointer to an encrypted patient goal message.
 * It is deliberately separate from clinician-authored goals and care plans.
 */
class PatientAuthoredGoal extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'goal_uuid';

    protected $table = 'patient_experience.patient_authored_goals';

    protected $primaryKey = 'patient_authored_goal_id';

    protected $fillable = [
        'goal_uuid',
        'principal_id',
        'access_grant_id',
        'message_thread_id',
        'source_message_id',
        'policy_version',
        'idempotency_key_digest',
        'request_payload_digest',
        'submitted_at',
    ];

    protected $hidden = [
        'principal_id',
        'access_grant_id',
        'message_thread_id',
        'source_message_id',
        'idempotency_key_digest',
        'request_payload_digest',
    ];

    protected $casts = [
        'principal_id' => 'integer',
        'access_grant_id' => 'integer',
        'message_thread_id' => 'integer',
        'source_message_id' => 'integer',
        'submitted_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'principal_id', 'principal_id');
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(PatientEncounterAccessGrant::class, 'access_grant_id', 'access_grant_id');
    }

    public function messageThread(): BelongsTo
    {
        return $this->belongsTo(PatientMessageThread::class, 'message_thread_id', 'message_thread_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'source_message_id', 'message_id');
    }
}
