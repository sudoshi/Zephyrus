<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, content-free association for a patient-authored preference. The
 * preference text exists only in the existing encrypted patient-message ledger
 * and is never a clinical care-plan, order, consent, or assessment record.
 */
class PatientCarePreference extends Model
{
    use AssignsExternalUuid;
    use IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'preference_uuid';

    protected $table = 'patient_experience.care_preferences';

    protected $primaryKey = 'care_preference_id';

    protected $fillable = [
        'preference_uuid',
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
