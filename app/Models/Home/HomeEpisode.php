<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use App\Models\Encounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomeEpisode extends Model
{
    protected $table = 'prod.home_episodes';

    protected $primaryKey = 'home_episode_id';

    protected $guarded = [];

    protected $casts = [
        'acuity_tier' => 'integer',
        'target_los_days' => 'float',
        'expected_discharge_date' => 'immutable_date',
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(HomeProgram::class, 'home_program_id', 'home_program_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(HomeReferral::class, 'home_referral_id', 'home_referral_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(RpmEnrollment::class, 'home_episode_id', 'home_episode_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(HomeVisit::class, 'home_episode_id', 'home_episode_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(HomeEscalation::class, 'home_episode_id', 'home_episode_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(HomeTransition::class, 'home_episode_id', 'home_episode_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(RpmAlert::class, 'home_episode_id', 'home_episode_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('is_deleted', false);
    }
}
