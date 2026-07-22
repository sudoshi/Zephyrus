<?php

namespace App\Models\PatientCommunication;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\PatientEncounterAccessGrant;
use App\Models\Patient\PatientMessageThread;
use App\Models\Patient\PatientNotificationOutbox;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThreadWorkItem extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'work_item_uuid';

    protected $table = 'patient_communications.thread_work_items';

    protected $primaryKey = 'thread_work_item_id';

    protected $fillable = [
        'work_item_uuid',
        'message_thread_id',
        'access_grant_id',
        'responsibility_pool_id',
        'assigned_user_id',
        'status',
        'ownership_state',
        'source_thread_version',
        'row_version',
        'last_outbox_id',
        'first_routed_at',
        'due_at',
        'escalate_at',
        'last_message_at',
        'acknowledged_at',
        'responded_at',
        'closed_at',
        'close_reason_code',
    ];

    protected $casts = [
        'message_thread_id' => 'integer',
        'access_grant_id' => 'integer',
        'responsibility_pool_id' => 'integer',
        'assigned_user_id' => 'integer',
        'source_thread_version' => 'integer',
        'row_version' => 'integer',
        'last_outbox_id' => 'integer',
        'first_routed_at' => 'immutable_datetime',
        'due_at' => 'immutable_datetime',
        'escalate_at' => 'immutable_datetime',
        'last_message_at' => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'responded_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(PatientMessageThread::class, 'message_thread_id', 'message_thread_id');
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(PatientEncounterAccessGrant::class, 'access_grant_id', 'access_grant_id');
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityPool::class, 'responsibility_pool_id', 'responsibility_pool_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id', 'id');
    }

    public function lastOutboxMessage(): BelongsTo
    {
        return $this->belongsTo(PatientNotificationOutbox::class, 'last_outbox_id', 'notification_outbox_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StaffActionEvent::class, 'thread_work_item_id', 'thread_work_item_id');
    }
}
