<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityDefinition extends Model
{
    protected $table = 'care_pathways.activity_definitions';

    protected $primaryKey = 'activity_definition_id';

    protected $guarded = [];

    protected $casts = [
        'timing' => 'array',
        'preconditions' => 'array',
        'executable' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'activity_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }
}
