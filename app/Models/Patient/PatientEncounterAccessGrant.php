<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientEncounterAccessGrant extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'grant_uuid';

    protected $table = 'patient_experience.encounter_access_grants';

    protected $primaryKey = 'access_grant_id';

    protected $attributes = [
        'relationship' => 'self',
        'scopes' => '["care_pathway", "care_team"]',
        'purpose_of_use' => 'patient_access',
        'status' => 'pending',
        'issued_by_actor_type' => 'system',
        'version' => 1,
        'metadata' => '{}',
    ];

    protected $fillable = [
        'grant_uuid',
        'principal_id',
        'identity_link_id',
        'encounter_uuid',
        'source_encounter_id',
        'encrypted_source_encounter_ref',
        'source_encounter_ref_digest',
        'source_system_key',
        'relationship',
        'scopes',
        'purpose_of_use',
        'status',
        'valid_from',
        'expires_at',
        'issued_by_actor_type',
        'issued_by_actor_ref',
        'grant_reason',
        'revoked_at',
        'revoked_by_actor_type',
        'revoked_by_actor_ref',
        'revocation_reason',
        'version',
        'metadata',
    ];

    protected $hidden = [
        'source_encounter_id',
        'encrypted_source_encounter_ref',
        'source_encounter_ref_digest',
    ];

    protected $casts = [
        'source_encounter_id' => 'integer',
        'encrypted_source_encounter_ref' => 'encrypted',
        'scopes' => 'array',
        'valid_from' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'version' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (PatientEncounterAccessGrant $grant): void {
            if (blank($grant->encounter_uuid)) {
                $grant->encounter_uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'principal_id', 'principal_id');
    }

    public function identityLink(): BelongsTo
    {
        return $this->belongsTo(PatientIdentityLink::class, 'identity_link_id', 'identity_link_id');
    }

    public function enrollmentChallenges(): HasMany
    {
        return $this->hasMany(PatientEnrollmentChallenge::class, 'access_grant_id', 'access_grant_id');
    }

    public function accessAuditEvents(): HasMany
    {
        return $this->hasMany(PatientAccessAuditEvent::class, 'access_grant_id', 'access_grant_id');
    }

    public function notificationOutboxMessages(): HasMany
    {
        return $this->hasMany(PatientNotificationOutbox::class, 'access_grant_id', 'access_grant_id');
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('valid_from', '<=', now())
            ->where(function (Builder $window): void {
                $window->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function permits(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
