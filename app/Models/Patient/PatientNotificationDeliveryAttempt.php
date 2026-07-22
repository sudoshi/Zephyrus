<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientNotificationDeliveryAttempt extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'delivery_attempt_uuid';

    protected $table = 'patient_experience.notification_delivery_attempts';

    protected $primaryKey = 'notification_delivery_attempt_id';

    protected $attributes = [
        'metadata' => '{}',
    ];

    protected $fillable = [
        'delivery_attempt_uuid',
        'notification_outbox_id',
        'attempt_number',
        'status',
        'worker_ref',
        'provider_message_ref_digest',
        'error_code',
        'next_attempt_at',
        'metadata',
        'occurred_at',
    ];

    protected $hidden = [
        'provider_message_ref_digest',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'next_attempt_at' => 'immutable_datetime',
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function outboxMessage(): BelongsTo
    {
        return $this->belongsTo(PatientNotificationOutbox::class, 'notification_outbox_id', 'notification_outbox_id');
    }
}
