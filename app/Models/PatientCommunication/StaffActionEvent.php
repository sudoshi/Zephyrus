<?php

namespace App\Models\PatientCommunication;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use App\Models\Patient\PatientMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffActionEvent extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'event_uuid';

    protected $table = 'patient_communications.staff_action_events';

    protected $primaryKey = 'staff_action_event_id';

    protected $attributes = [
        'metadata' => '{}',
    ];

    protected $fillable = [
        'event_uuid',
        'thread_work_item_id',
        'event_type',
        'actor_user_id',
        'from_pool_id',
        'to_pool_id',
        'from_user_id',
        'to_user_id',
        'message_id',
        'reason_code',
        'patient_visible_state',
        'idempotency_key_digest',
        'request_payload_digest',
        'metadata',
        'occurred_at',
    ];

    protected $hidden = [
        'idempotency_key_digest',
        'request_payload_digest',
        'metadata',
    ];

    protected $casts = [
        'thread_work_item_id' => 'integer',
        'actor_user_id' => 'integer',
        'from_pool_id' => 'integer',
        'to_pool_id' => 'integer',
        'from_user_id' => 'integer',
        'to_user_id' => 'integer',
        'message_id' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(ThreadWorkItem::class, 'thread_work_item_id', 'thread_work_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'message_id', 'message_id');
    }
}
