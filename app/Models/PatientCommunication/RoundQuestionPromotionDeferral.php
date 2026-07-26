<?php

namespace App\Models\PatientCommunication;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\PatientMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only, patient-safe record that a promoted question was deferred
 * for later review. It owns no question text, internal reason, or staff-only
 * rounds deliberation; those remain in their canonical restricted stores.
 */
class RoundQuestionPromotionDeferral extends Model
{
    use AssignsExternalUuid;

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'deferral_uuid';

    protected $table = 'patient_communications.round_question_promotion_deferrals';

    protected $primaryKey = 'round_question_promotion_deferral_id';

    protected $fillable = [
        'deferral_uuid',
        'round_question_promotion_id',
        'patient_status_message_id',
        'deferred_by_user_id',
        'deferral_policy_version',
        'idempotency_key_digest',
        'request_payload_digest',
        'deferred_at',
    ];

    protected $hidden = [
        'idempotency_key_digest',
        'request_payload_digest',
    ];

    protected $casts = [
        'round_question_promotion_id' => 'integer',
        'patient_status_message_id' => 'integer',
        'deferred_by_user_id' => 'integer',
        'deferred_at' => 'immutable_datetime',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(
            RoundQuestionPromotion::class,
            'round_question_promotion_id',
            'round_question_promotion_id',
        );
    }

    public function patientStatusMessage(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'patient_status_message_id', 'message_id');
    }

    public function deferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deferred_by_user_id', 'id');
    }
}
