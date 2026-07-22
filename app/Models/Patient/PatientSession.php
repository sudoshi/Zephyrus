<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientSession extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'session_uuid';

    protected $table = 'patient_experience.sessions';

    protected $primaryKey = 'patient_session_id';

    protected $attributes = [
        'status' => 'active',
    ];

    protected $fillable = [
        'session_uuid',
        'principal_id',
        'token_family_uuid',
        'refresh_token_id',
        'status',
        'device_uuid',
        'platform',
        'device_name',
        'app_version',
        'os_version',
        'auth_method',
        'assurance_level',
        'client_instance_digest',
        'user_agent_digest',
        'ip_address',
        'last_authenticated_at',
        'last_seen_at',
        'expires_at',
        'idle_expires_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected $hidden = [
        'refresh_token_id',
        'client_instance_digest',
        'user_agent_digest',
        'ip_address',
    ];

    protected $casts = [
        'refresh_token_id' => 'integer',
        'last_authenticated_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'idle_expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PatientSession $session): void {
            if (blank($session->token_family_uuid)) {
                $session->token_family_uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'principal_id', 'principal_id');
    }

    public function accessAuditEvents(): HasMany
    {
        return $this->hasMany(PatientAccessAuditEvent::class, 'patient_session_id', 'patient_session_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->where(function (Builder $idle): void {
                $idle->whereNull('idle_expires_at')->orWhere('idle_expires_at', '>', now());
            });
    }
}
