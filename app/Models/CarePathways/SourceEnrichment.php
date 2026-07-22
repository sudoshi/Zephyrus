<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceEnrichment extends Model
{
    protected $table = 'care_pathways.source_enrichments';

    protected $primaryKey = 'source_enrichment_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'source_record' => 'array',
        'verified_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'enrichment_uuid';
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PathwaySource::class, 'source_id');
    }
}
