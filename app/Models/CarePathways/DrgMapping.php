<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrgMapping extends Model
{
    protected $table = 'care_pathways.drg_mappings';

    protected $primaryKey = 'drg_mapping_id';

    public $timestamps = false;

    protected $guarded = [];

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }

    public function codebookEntry(): BelongsTo
    {
        return $this->belongsTo(DrgCodebookEntry::class, 'drg_codebook_entry_id');
    }
}
