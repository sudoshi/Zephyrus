<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceLineMapping extends Model
{
    protected $table = 'care_pathways.service_line_mappings';

    protected $primaryKey = 'service_line_mapping_id';

    protected $guarded = [];

    protected $casts = [
        'mapped_at' => 'immutable_datetime',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }
}
