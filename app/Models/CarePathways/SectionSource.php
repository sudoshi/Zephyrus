<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionSource extends Model
{
    protected $table = 'care_pathways.section_sources';

    protected $primaryKey = 'section_source_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'provenance' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(PathwaySection::class, 'pathway_section_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PathwaySource::class, 'source_id');
    }
}
