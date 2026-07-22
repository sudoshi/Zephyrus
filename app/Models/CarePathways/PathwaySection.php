<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PathwaySection extends Model
{
    protected $table = 'care_pathways.sections';

    protected $primaryKey = 'pathway_section_id';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'section_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(SectionSource::class, 'pathway_section_id');
    }
}
