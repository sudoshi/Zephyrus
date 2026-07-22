<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientMessageThread extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'thread_uuid';

    protected $table = 'patient_experience.message_threads';

    protected $primaryKey = 'message_thread_id';

    protected $attributes = [
        'status' => 'open',
        'ownership_state' => 'awaiting_team',
        'version' => 1,
    ];

    protected $fillable = [
        'thread_uuid',
        'access_grant_id',
        'opened_by_principal_id',
        'topic_code',
        'topic_label',
        'topic_description',
        'status',
        'ownership_state',
        'routing_policy_version',
        'expected_response_window',
        'urgent_guidance_version',
        'responsibility_pool_ref_digest',
        'creation_idempotency_key_digest',
        'creation_request_payload_digest',
        'version',
        'last_message_at',
        'closed_at',
        'close_reason_code',
    ];

    protected $hidden = [
        'responsibility_pool_ref_digest',
        'creation_idempotency_key_digest',
        'creation_request_payload_digest',
    ];

    protected $casts = [
        'access_grant_id' => 'integer',
        'opened_by_principal_id' => 'integer',
        'version' => 'integer',
        'last_message_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(PatientEncounterAccessGrant::class, 'access_grant_id', 'access_grant_id');
    }

    public function openedByPrincipal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'opened_by_principal_id', 'principal_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PatientMessage::class, 'message_thread_id', 'message_thread_id');
    }

    public function routingEvents(): HasMany
    {
        return $this->hasMany(PatientMessageRoutingEvent::class, 'message_thread_id', 'message_thread_id');
    }
}
