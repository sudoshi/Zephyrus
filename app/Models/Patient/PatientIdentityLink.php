<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientIdentityLink extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'identity_link_uuid';

    protected $table = 'patient_experience.identity_links';

    protected $primaryKey = 'identity_link_id';

    protected $attributes = [
        'digest_algorithm' => 'hmac-sha256',
        'status' => 'pending',
        'provenance' => '{}',
    ];

    protected $fillable = [
        'identity_link_uuid',
        'principal_id',
        'source_system_key',
        'encrypted_source_subject',
        'encryption_key_version',
        'source_subject_digest',
        'digest_algorithm',
        'linkage_method',
        'status',
        'assurance_level',
        'provenance',
        'verified_at',
        'revoked_at',
        'merged_at',
        'merged_into_identity_link_id',
    ];

    protected $hidden = [
        'encrypted_source_subject',
        'source_subject_digest',
    ];

    protected $casts = [
        'encrypted_source_subject' => 'encrypted',
        'provenance' => 'array',
        'verified_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'merged_at' => 'immutable_datetime',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'principal_id', 'principal_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_identity_link_id', 'identity_link_id');
    }

    public function mergedLinks(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_identity_link_id', 'identity_link_id');
    }

    public function encounterAccessGrants(): HasMany
    {
        return $this->hasMany(PatientEncounterAccessGrant::class, 'identity_link_id', 'identity_link_id');
    }

    public function enrollmentChallenges(): HasMany
    {
        return $this->hasMany(PatientEnrollmentChallenge::class, 'identity_link_id', 'identity_link_id');
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', 'verified')->whereNotNull('verified_at');
    }
}
