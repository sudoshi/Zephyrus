<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PathwayStageDefinition extends Model
{
    protected $table = 'care_pathways.stage_definitions';

    protected $primaryKey = 'stage_definition_id';

    protected $guarded = [];

    protected $casts = [
        'display_order' => 'integer',
        'expected_range' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'stage_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }
}
