<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogReleaseControl extends Model
{
    protected $table = 'care_pathways.catalog_release_controls';

    protected $primaryKey = 'catalog_release_control_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'observed_value' => 'array',
        'reference_value' => 'array',
        'evidence' => 'array',
        'recorded_at' => 'immutable_datetime',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }
}
