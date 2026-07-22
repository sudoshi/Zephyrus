<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceClaim extends Model
{
    protected $table = 'care_pathways.evidence_claims';

    protected $primaryKey = 'evidence_claim_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'verification_date' => 'immutable_date',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ClaimSource::class, 'evidence_claim_id');
    }
}
