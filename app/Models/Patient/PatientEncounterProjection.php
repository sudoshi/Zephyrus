<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use App\Services\Patient\Projection\PatientProjectionContentGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientEncounterProjection extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'projection_uuid';

    protected $table = 'patient_experience.encounter_projections';

    protected $primaryKey = 'encounter_projection_id';

    protected $attributes = [
        'provenance' => '{}',
        'uncertainty' => '{}',
        'permitted_relationships' => '["self"]',
        'release_state' => 'draft',
    ];

    protected $fillable = [
        'projection_uuid',
        'access_grant_id',
        'release_policy_version_id',
        'projection_cursor_id',
        'supersedes_projection_id',
        'projection_kind',
        'projection_sequence',
        'content',
        'content_schema_version',
        'content_digest',
        'source_version',
        'provenance',
        'source_observed_at',
        'generated_at',
        'released_at',
        'freshness_class',
        'uncertainty',
        'required_scope',
        'permitted_relationships',
        'release_state',
    ];

    protected $hidden = [
        'access_grant_id',
        'release_policy_version_id',
        'projection_cursor_id',
        'supersedes_projection_id',
        'source_version',
        'content_digest',
        'provenance',
    ];

    protected $casts = [
        'access_grant_id' => 'integer',
        'release_policy_version_id' => 'integer',
        'projection_cursor_id' => 'integer',
        'supersedes_projection_id' => 'integer',
        'projection_sequence' => 'integer',
        'content' => 'array',
        'provenance' => 'array',
        'source_observed_at' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'released_at' => 'immutable_datetime',
        'uncertainty' => 'array',
        'permitted_relationships' => 'array',
        'recorded_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PatientEncounterProjection $projection): void {
            $requiredScope = [
                'today' => 'today:read',
                'pathway' => 'pathway:read',
                'pathway_events' => 'pathway:read',
                'discharge_readiness' => 'pathway:read',
                'rounds_summary' => 'pathway:read',
                'care_team' => 'care_team:read',
            ][(string) $projection->projection_kind] ?? null;
            if ($requiredScope === null || $projection->required_scope !== $requiredScope) {
                throw new \InvalidArgumentException('patient_projection_scope_mismatch');
            }

            if ($projection->supersedes_projection_id !== null) {
                $superseded = self::query()->find($projection->supersedes_projection_id);
                if ($superseded === null
                    || (int) $superseded->access_grant_id !== (int) $projection->access_grant_id
                    || $superseded->projection_kind !== $projection->projection_kind
                    || (int) $superseded->projection_sequence >= (int) $projection->projection_sequence) {
                    throw new \InvalidArgumentException('patient_projection_supersession_invalid');
                }
            }

            $guard = app(PatientProjectionContentGuard::class);
            $content = (array) $projection->content;
            $guard->assertSafe(
                (string) $projection->projection_kind,
                $content,
                (array) $projection->provenance,
                (array) $projection->uncertainty,
                array_values((array) $projection->permitted_relationships),
            );

            $digest = $guard->digest(
                (string) $projection->projection_kind,
                (string) $projection->content_schema_version,
                $content,
            );

            if (blank($projection->content_digest)) {
                $projection->content_digest = $digest;
            } elseif (! hash_equals((string) $projection->content_digest, $digest)) {
                throw new \InvalidArgumentException('patient_projection_content_digest_mismatch');
            }
        });
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(PatientEncounterAccessGrant::class, 'access_grant_id', 'access_grant_id');
    }

    public function releasePolicyVersion(): BelongsTo
    {
        return $this->belongsTo(
            PatientReleasePolicyVersion::class,
            'release_policy_version_id',
            'release_policy_version_id',
        );
    }

    public function cursor(): BelongsTo
    {
        return $this->belongsTo(PatientProjectionCursor::class, 'projection_cursor_id', 'projection_cursor_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_projection_id', 'encounter_projection_id');
    }

    public function contentActions(): HasMany
    {
        return $this->hasMany(PatientContentAction::class, 'target_projection_id', 'encounter_projection_id');
    }
}
