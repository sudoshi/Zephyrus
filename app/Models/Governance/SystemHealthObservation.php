<?php

namespace App\Models\Governance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SystemHealthObservation extends Model
{
    protected $table = 'governance.system_health_observations';

    protected $primaryKey = 'system_health_observation_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'observed_at' => 'immutable_datetime',
        'freshness_expires_at' => 'immutable_datetime',
        'required' => 'boolean',
        'duration_ms' => 'integer',
        'details' => 'array',
        'recorded_by_user_id' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
