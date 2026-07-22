<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneDefinition extends Model
{
    protected $table = 'care_pathways.milestone_definitions';

    protected $primaryKey = 'milestone_definition_id';

    protected $guarded = [];

    protected $casts = [
        'predecessor_keys' => 'array',
        'expected_range' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'milestone_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }
}
