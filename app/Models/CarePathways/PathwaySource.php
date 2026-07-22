<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PathwaySource extends Model
{
    protected $table = 'care_pathways.sources';

    protected $primaryKey = 'source_id';

    protected $guarded = [];

    protected $casts = [
        'publication_types' => 'array',
        'provenance' => 'array',
        'verified_date' => 'immutable_date',
    ];

    public function claimSources(): HasMany
    {
        return $this->hasMany(ClaimSource::class, 'source_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(SourceStatusEvent::class, 'source_id');
    }

    public function releaseMemberships(): HasMany
    {
        return $this->hasMany(CatalogReleaseSource::class, 'source_id');
    }

    public function sectionSources(): HasMany
    {
        return $this->hasMany(SectionSource::class, 'source_id');
    }

    public function enrichments(): HasMany
    {
        return $this->hasMany(SourceEnrichment::class, 'source_id');
    }

    public function completenessResolutions(): HasMany
    {
        return $this->hasMany(CompletenessResolution::class, 'source_id');
    }
}
