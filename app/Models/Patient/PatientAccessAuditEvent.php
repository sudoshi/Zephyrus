<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAccessAuditEvent extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'event_uuid';

    protected $table = 'patient_experience.access_audit_events';

    protected $primaryKey = 'access_audit_event_id';

    protected $attributes = [
        'metadata' => '{}',
        'schema_version' => 1,
    ];

    protected $fillable = [
        'event_uuid',
        'principal_id',
        'patient_session_id',
        'access_grant_id',
        'actor_type',
        'actor_ref',
        'event_type',
        'category',
        'action',
        'outcome',
        'purpose_of_use',
        'reason_code',
        'resource_type',
        'resource_uuid',
        'request_uuid',
        'correlation_uuid',
        'idempotency_key_digest',
        'ip_address',
        'user_agent_digest',
        'metadata',
        'schema_version',
        'occurred_at',
    ];

    protected $hidden = [
        'ip_address',
        'user_agent_digest',
        'idempotency_key_digest',
    ];

    protected $casts = [
        'principal_id' => 'integer',
        'patient_session_id' => 'integer',
        'access_grant_id' => 'integer',
        'metadata' => 'array',
        'schema_version' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'principal_id', 'principal_id');
    }

    public function patientSession(): BelongsTo
    {
        return $this->belongsTo(PatientSession::class, 'patient_session_id', 'patient_session_id');
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(PatientEncounterAccessGrant::class, 'access_grant_id', 'access_grant_id');
    }
}
