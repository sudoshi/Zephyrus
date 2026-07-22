<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientMessage extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'message_uuid';

    protected $table = 'patient_experience.messages';

    protected $primaryKey = 'message_id';

    protected $attributes = [
        'visibility' => 'patient_visible',
        'message_kind' => 'message',
        'delivery_state' => 'accepted',
        'body_character_count' => 0,
    ];

    protected $fillable = [
        'message_uuid',
        'message_thread_id',
        'sender_type',
        'sender_principal_id',
        'sender_actor_ref_digest',
        'visibility',
        'message_kind',
        'relates_to_message_id',
        'encrypted_body',
        'encryption_key_version',
        'body_digest',
        'body_character_count',
        'client_message_uuid',
        'idempotency_key_digest',
        'request_payload_digest',
        'delivery_state',
        'sent_at',
    ];

    protected $hidden = [
        'encrypted_body',
        'sender_actor_ref_digest',
        'body_digest',
        'idempotency_key_digest',
        'request_payload_digest',
    ];

    protected $casts = [
        'message_thread_id' => 'integer',
        'sender_principal_id' => 'integer',
        'relates_to_message_id' => 'integer',
        'body_character_count' => 'integer',
        'sent_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(PatientMessageThread::class, 'message_thread_id', 'message_thread_id');
    }

    public function senderPrincipal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'sender_principal_id', 'principal_id');
    }

    public function relatesTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'relates_to_message_id', 'message_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PatientMessageDeliveryReceipt::class, 'message_id', 'message_id');
    }
}
