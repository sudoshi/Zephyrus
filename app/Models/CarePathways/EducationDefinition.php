<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationDefinition extends Model
{
    protected $table = 'care_pathways.education_definitions';

    protected $primaryKey = 'education_definition_id';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'education_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }
}
