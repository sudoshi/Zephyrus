<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimSource extends Model
{
    protected $table = 'care_pathways.claim_sources';

    protected $primaryKey = 'claim_source_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'provenance' => 'array',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(EvidenceClaim::class, 'evidence_claim_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PathwaySource::class, 'source_id');
    }
}
