<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeVisit extends Model
{
    protected $table = 'prod.home_visits';

    protected $primaryKey = 'home_visit_id';

    protected $guarded = [];

    protected $casts = [
        'is_waiver_required' => 'boolean',
        'scheduled_start' => 'immutable_datetime',
        'scheduled_end' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'on_time' => 'boolean',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(HomeEpisode::class, 'home_episode_id', 'home_episode_id');
    }
}
