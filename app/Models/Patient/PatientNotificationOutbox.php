<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientNotificationOutbox extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'outbox_uuid';

    protected $table = 'patient_experience.notification_outbox';

    protected $primaryKey = 'notification_outbox_id';

    protected $attributes = [
        'routing_metadata' => '{}',
    ];

    protected $fillable = [
        'outbox_uuid',
        'principal_id',
        'access_grant_id',
        'aggregate_type',
        'aggregate_uuid',
        'event_type',
        'destination',
        'encrypted_payload',
        'encryption_key_version',
        'payload_digest',
        'routing_metadata',
        'idempotency_key_digest',
        'available_at',
        'expires_at',
        'occurred_at',
    ];

    protected $hidden = [
        'encrypted_payload',
        'payload_digest',
        'idempotency_key_digest',
    ];

    protected $casts = [
        'encrypted_payload' => 'encrypted:array',
        'routing_metadata' => 'array',
        'available_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'occurred_at' => 'immutable_datetime',
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

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(PatientNotificationDeliveryAttempt::class, 'notification_outbox_id', 'notification_outbox_id');
    }
}
