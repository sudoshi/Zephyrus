<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class PatientEnrollmentChallenge extends Model
{
    use AssignsExternalUuid;

    public const EXTERNAL_UUID_COLUMN = 'challenge_uuid';

    protected $table = 'patient_experience.enrollment_challenges';

    protected $primaryKey = 'enrollment_challenge_id';

    protected $attributes = [
        'purpose' => 'encounter_enrollment',
        'delivery_method' => 'portal',
        'status' => 'issued',
        'failed_attempts' => 0,
        'max_attempts' => 5,
        'metadata' => '{}',
    ];

    protected $fillable = [
        'challenge_uuid',
        'principal_id',
        'identity_link_id',
        'access_grant_id',
        'challenge_hash',
        'code_hash',
        'purpose',
        'delivery_method',
        'status',
        'failed_attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'metadata',
    ];

    protected $hidden = [
        'challenge_hash',
        'code_hash',
    ];

    protected $casts = [
        'failed_attempts' => 'integer',
        'max_attempts' => 'integer',
        'expires_at' => 'immutable_datetime',
        'consumed_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    public function principal(): BelongsTo
    {
        return $this->belongsTo(PatientPrincipal::class, 'principal_id', 'principal_id');
    }

    public function identityLink(): BelongsTo
    {
        return $this->belongsTo(PatientIdentityLink::class, 'identity_link_id', 'identity_link_id');
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(PatientEncounterAccessGrant::class, 'access_grant_id', 'access_grant_id');
    }

    public function matchesChallengeToken(string $token): bool
    {
        return Hash::check($token, $this->challenge_hash);
    }

    public function matchesVerificationCode(string $code): bool
    {
        return $this->code_hash !== null && Hash::check($code, $this->code_hash);
    }

    public function isUsable(): bool
    {
        return $this->status === 'issued'
            && $this->failed_attempts < $this->max_attempts
            && $this->expires_at->isFuture();
    }
}
