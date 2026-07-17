<?php

namespace App\Models\Home;

use App\Casts\JsonObject;
use App\Models\Transport\TransportRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeTransition extends Model
{
    protected $table = 'prod.home_transitions';

    protected $primaryKey = 'home_transition_id';

    protected $guarded = [];

    protected $casts = [
        'checklist' => JsonObject::class,
        'barriers' => 'array',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'metadata' => JsonObject::class,
        'is_deleted' => 'boolean',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(HomeEpisode::class, 'home_episode_id', 'home_episode_id');
    }

    // regional.facilities has no Eloquent model (services query it raw);
    // regional_facility_id stays a plain FK attribute.
    public function transportRequest(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class, 'transport_request_id', 'transport_request_id');
    }
}
