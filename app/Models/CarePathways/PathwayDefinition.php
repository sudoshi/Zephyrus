<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PathwayDefinition extends Model
{
    protected $table = 'care_pathways.definitions';

    protected $primaryKey = 'pathway_definition_id';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'pathway_uuid';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PathwayVersion::class, 'pathway_definition_id');
    }
}
