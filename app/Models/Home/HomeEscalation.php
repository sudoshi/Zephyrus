<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeEscalation extends Model
{
    protected $table = 'prod.home_escalations';

    protected $primaryKey = 'home_escalation_id';

    protected $guarded = [];

    protected $casts = [
        'initiated_at' => 'immutable_datetime',
        'dispatched_at' => 'immutable_datetime',
        'arrived_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
        'response_minutes' => 'integer',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(HomeEpisode::class, 'home_episode_id', 'home_episode_id');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(RpmAlert::class, 'rpm_alert_id', 'rpm_alert_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'responding'])->where('is_deleted', false);
    }
}
