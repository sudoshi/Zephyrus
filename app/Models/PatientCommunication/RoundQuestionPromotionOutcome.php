<?php

namespace App\Models\PatientCommunication;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\PatientMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoundQuestionPromotionOutcome extends Model
{
    use AssignsExternalUuid;

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'outcome_uuid';

    protected $table = 'patient_communications.round_question_promotion_outcomes';

    protected $primaryKey = 'round_question_promotion_outcome_id';

    protected $fillable = [
        'outcome_uuid',
        'round_question_promotion_id',
        'patient_status_message_id',
        'resolved_by_user_id',
        'resolved_status',
        'outcome_policy_version',
        'resolved_at',
    ];

    protected $casts = [
        'round_question_promotion_id' => 'integer',
        'patient_status_message_id' => 'integer',
        'resolved_by_user_id' => 'integer',
        'resolved_at' => 'immutable_datetime',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(RoundQuestionPromotion::class, 'round_question_promotion_id', 'round_question_promotion_id');
    }

    public function patientStatusMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'patient_status_message_id', 'message_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id', 'id');
    }
}
