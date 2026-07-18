<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpmAlert extends Model
{
    protected $table = 'prod.rpm_alerts';

    protected $primaryKey = 'rpm_alert_id';

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(HomeEpisode::class, 'home_episode_id', 'home_episode_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(RpmEnrollment::class, 'rpm_enrollment_id', 'rpm_enrollment_id');
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(RpmObservation::class, 'rpm_observation_id', 'rpm_observation_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')->where('is_deleted', false);
    }
}
