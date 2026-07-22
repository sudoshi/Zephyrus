<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrgCodebookEntry extends Model
{
    protected $table = 'care_pathways.drg_codebook_entries';

    protected $primaryKey = 'drg_codebook_entry_id';

    public $timestamps = false;

    protected $guarded = [];

    public function release(): BelongsTo
    {
        return $this->belongsTo(CatalogRelease::class, 'catalog_release_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(DrgMapping::class, 'drg_codebook_entry_id');
    }
}
