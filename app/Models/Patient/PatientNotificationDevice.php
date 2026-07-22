<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientNotificationDevice extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'device_uuid';

    protected $table = 'patient_experience.notification_devices';

    protected $primaryKey = 'notification_device_id';

    protected $fillable = [
        'device_uuid', 'principal_id', 'platform', 'environment', 'installation_uuid',
        'encrypted_push_token', 'encryption_key_version', 'push_token_digest',
        'app_version', 'os_version', 'locale', 'status', 'last_seen_at', 'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = ['encrypted_push_token', 'push_token_digest'];

    protected $casts = [
        'last_seen_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'principal_id', 'principal_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
