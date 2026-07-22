<?php

namespace App\Models\Patient;

use App\Models\Patient\Concerns\AssignsExternalUuid;
use App\Models\Patient\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientProjectionFailure extends Model
{
    use AssignsExternalUuid, IsAppendOnly;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    public const EXTERNAL_UUID_COLUMN = 'failure_uuid';

    protected $table = 'patient_experience.source_projection_failures';

    protected $primaryKey = 'projection_failure_id';

    protected $attributes = [
        'attempt_number' => 1,
        'context' => '{}',
    ];

    protected $fillable = [
        'failure_uuid',
        'projection_cursor_id',
        'source_system_key',
        'projection_kind',
        'failure_code',
        'retryability',
        'attempt_number',
        'source_observed_at',
        'occurred_at',
        'context',
    ];

    protected $hidden = [
        'source_system_key',
        'context',
    ];

    protected $casts = [
        'projection_cursor_id' => 'integer',
        'attempt_number' => 'integer',
        'context' => 'array',
        'source_observed_at' => 'immutable_datetime',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
    ];

    public function cursor(): BelongsTo
    {
        return $this->belongsTo(PatientProjectionCursor::class, 'projection_cursor_id', 'projection_cursor_id');
    }
}
