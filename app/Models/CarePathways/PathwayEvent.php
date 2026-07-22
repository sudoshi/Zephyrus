<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;

class PathwayEvent extends Model
{
    protected $table = 'care_pathways.events';

    protected $primaryKey = 'pathway_event_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'event_uuid';
    }
}
