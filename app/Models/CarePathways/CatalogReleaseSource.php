<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogReleaseSource extends Model
{
    protected $table = 'care_pathways.catalog_release_sources';

    protected $primaryKey = 'catalog_release_source_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PathwaySource::class, 'source_id');
    }
}
