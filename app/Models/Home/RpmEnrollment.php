<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RpmEnrollment extends Model
{
    protected $table = 'prod.rpm_enrollments';

    protected $primaryKey = 'rpm_enrollment_id';

    protected $guarded = [];

    protected $casts = [
        'monitoring_plan' => JsonObject::class,
        'baseline' => JsonObject::class,
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(HomeEpisode::class, 'home_episode_id', 'home_episode_id');
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(RpmKit::class, 'rpm_kit_id', 'rpm_kit_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(RpmObservation::class, 'rpm_enrollment_id', 'rpm_enrollment_id');
    }
}
