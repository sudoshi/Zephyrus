<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class PatientPrincipal extends Authenticatable
{
    use AssignsExternalUuid, HasApiTokens, Notifiable;

    public const EXTERNAL_UUID_COLUMN = 'principal_uuid';

    protected $table = 'patient_experience.principals';

    protected $primaryKey = 'principal_id';

    protected $attributes = [
        'principal_type' => 'patient',
        'status' => 'pending',
        'is_active' => false,
        'preferences' => '{}',
        'locale' => 'en-US',
        'timezone' => 'UTC',
    ];

    protected $fillable = [
        'principal_uuid',
        'principal_type',
        'display_name',
        'email',
        'phone_e164',
        'password',
        'status',
        'is_active',
        'preferences',
        'locale',
        'timezone',
        'email_verified_at',
        'phone_verified_at',
        'last_authenticated_at',
        'locked_at',
        'closed_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'preferences' => 'array',
        'email_verified_at' => 'immutable_datetime',
        'phone_verified_at' => 'immutable_datetime',
        'last_authenticated_at' => 'immutable_datetime',
        'locked_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (PatientPrincipal $principal): void {
            if ($principal->email !== null) {
                $principal->email = Str::lower(trim($principal->email));
            }
        });
    }

    public function identityLinks(): HasMany
    {
        return $this->hasMany(PatientIdentityLink::class, 'principal_id', 'principal_id');
    }

    public function encounterAccessGrants(): HasMany
    {
        return $this->hasMany(PatientEncounterAccessGrant::class, 'principal_id', 'principal_id');
    }

    public function enrollmentChallenges(): HasMany
    {
        return $this->hasMany(PatientEnrollmentChallenge::class, 'principal_id', 'principal_id');
    }

    public function patientSessions(): HasMany
    {
        return $this->hasMany(PatientSession::class, 'principal_id', 'principal_id');
    }

    public function accessAuditEvents(): HasMany
    {
        return $this->hasMany(PatientAccessAuditEvent::class, 'principal_id', 'principal_id');
    }

    public function notificationOutboxMessages(): HasMany
    {
        return $this->hasMany(PatientNotificationOutbox::class, 'principal_id', 'principal_id');
    }

    public function notificationDevices(): HasMany
    {
        return $this->hasMany(PatientNotificationDevice::class, 'principal_id', 'principal_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('is_active', true);
    }
}
