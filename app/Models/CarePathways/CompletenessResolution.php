<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompletenessResolution extends Model
{
    protected $table = 'care_pathways.completeness_resolutions';

    protected $primaryKey = 'completeness_resolution_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'evidence' => 'array',
        'raw_record' => 'array',
        'audited_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'resolution_uuid';
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
