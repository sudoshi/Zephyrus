<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogRelease extends Model
{
    protected $table = 'care_pathways.catalog_releases';

    protected $primaryKey = 'catalog_release_id';

    protected $guarded = [];

    protected $casts = [
        'source_controls' => 'array',
        'clinical_signoff_complete' => 'boolean',
        'grouper_effective_start' => 'immutable_date',
        'grouper_effective_end' => 'immutable_date',
        'adopted_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
        'withdrawn_at' => 'immutable_datetime',
        'coverage_control_percent' => 'decimal:3',
    ];

    public function getRouteKeyName(): string
    {
        return 'catalog_release_uuid';
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PathwayVersion::class, 'catalog_release_id');
    }

    public function controls(): HasMany
    {
        return $this->hasMany(CatalogReleaseControl::class, 'catalog_release_id');
    }

    public function releaseSources(): HasMany
    {
        return $this->hasMany(CatalogReleaseSource::class, 'catalog_release_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SourceChange::class, 'catalog_release_id');
    }

    public function enrichments(): HasMany
    {
        return $this->hasMany(SourceEnrichment::class, 'catalog_release_id');
    }

    public function completenessResolutions(): HasMany
    {
        return $this->hasMany(CompletenessResolution::class, 'catalog_release_id');
    }

    public function serviceLineMappings(): HasMany
    {
        return $this->hasMany(ServiceLineMapping::class, 'catalog_release_id');
    }
}
