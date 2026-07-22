<?php

namespace App\Models\PatientCommunication;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\PatientMessage;
use App\Models\Patient\PatientMessageThread;
use App\Models\Rounds\RoundPatient;
use App\Models\Rounds\RoundQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RoundQuestionPromotion extends Model
{
    use AssignsExternalUuid;

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'promotion_uuid';

    protected $table = 'patient_communications.round_question_promotions';

    protected $primaryKey = 'round_question_promotion_id';

    protected $fillable = [
        'promotion_uuid',
        'message_thread_id',
        'source_message_id',
        'patient_status_message_id',
        'round_patient_id',
        'round_question_id',
        'promoted_by_user_id',
        'promotion_policy_version',
        'idempotency_key_digest',
        'request_payload_digest',
        'promoted_at',
    ];

    protected $hidden = [
        'idempotency_key_digest',
        'request_payload_digest',
    ];

    protected $casts = [
        'message_thread_id' => 'integer',
        'source_message_id' => 'integer',
        'patient_status_message_id' => 'integer',
        'round_patient_id' => 'integer',
        'round_question_id' => 'integer',
        'promoted_by_user_id' => 'integer',
        'promoted_at' => 'immutable_datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(PatientMessageThread::class, 'message_thread_id', 'message_thread_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'source_message_id', 'message_id');
    }

    public function patientStatusMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'patient_status_message_id', 'message_id');
    }

    public function roundPatient(): BelongsTo
    {
        return $this->belongsTo(RoundPatient::class, 'round_patient_id', 'round_patient_id');
    }

    public function roundQuestion(): BelongsTo
    {
        return $this->belongsTo(RoundQuestion::class, 'round_question_id', 'question_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by_user_id', 'id');
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(
            RoundQuestionPromotionOutcome::class,
            'round_question_promotion_id',
            'round_question_promotion_id',
        );
    }
}
