<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceChange extends Model
{
    protected $table = 'care_pathways.source_changes';

    protected $primaryKey = 'source_change_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'changed_on' => 'immutable_date',
        'created_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'change_uuid';
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }
}
