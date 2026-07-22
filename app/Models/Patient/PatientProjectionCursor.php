<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PatientProjectionCursor extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'cursor_uuid';

    protected $table = 'patient_experience.source_projection_cursors';

    protected $primaryKey = 'projection_cursor_id';

    protected $attributes = ['metadata' => '{}'];

    protected $fillable = [
        'cursor_uuid',
        'source_system_key',
        'projection_kind',
        'cursor_digest',
        'source_version',
        'status',
        'source_observed_at',
        'projected_at',
        'metadata',
    ];

    protected $hidden = [
        'source_system_key',
        'cursor_digest',
        'source_version',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'source_observed_at' => 'immutable_datetime',
        'projected_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function projections(): HasMany
    {
        return $this->hasMany(PatientEncounterProjection::class, 'projection_cursor_id', 'projection_cursor_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(PatientProjectionFailure::class, 'projection_cursor_id', 'projection_cursor_id');
    }
}
