<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMessageRoutingEvent extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'routing_event_uuid';

    protected $table = 'patient_experience.message_routing_events';

    protected $primaryKey = 'message_routing_event_id';

    protected $attributes = [
        'metadata' => '{}',
    ];

    protected $fillable = [
        'routing_event_uuid',
        'message_thread_id',
        'event_type',
        'from_pool_ref_digest',
        'to_pool_ref_digest',
        'actor_type',
        'actor_ref_digest',
        'reason_code',
        'patient_visible_state',
        'routing_policy_version',
        'idempotency_key_digest',
        'request_payload_digest',
        'metadata',
        'occurred_at',
    ];

    protected $hidden = [
        'from_pool_ref_digest',
        'to_pool_ref_digest',
        'actor_ref_digest',
        'idempotency_key_digest',
        'request_payload_digest',
        'metadata',
    ];

    protected $casts = [
        'message_thread_id' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(PatientMessageThread::class, 'message_thread_id', 'message_thread_id');
    }
}
