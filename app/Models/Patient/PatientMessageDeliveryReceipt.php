<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMessageDeliveryReceipt extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'receipt_uuid';

    protected $table = 'patient_experience.message_delivery_receipts';

    protected $primaryKey = 'message_delivery_receipt_id';

    protected $fillable = [
        'receipt_uuid',
        'message_id',
        'receipt_type',
        'actor_type',
        'actor_ref_digest',
        'patient_visible_state',
        'idempotency_key_digest',
        'occurred_at',
    ];

    protected $hidden = [
        'actor_ref_digest',
        'idempotency_key_digest',
    ];

    protected $casts = [
        'message_id' => 'integer',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(PatientMessage::class, 'message_id', 'message_id');
    }
}
