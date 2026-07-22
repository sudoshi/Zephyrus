<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalDefinition extends Model
{
    protected $table = 'care_pathways.goal_definitions';

    protected $primaryKey = 'goal_definition_id';

    protected $guarded = [];

    protected $casts = [
        'target' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'goal_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }
}
