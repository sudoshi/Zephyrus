<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PathwayReview extends Model
{
    protected $table = 'care_pathways.reviews';

    protected $primaryKey = 'pathway_review_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'issues' => 'array',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'review_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }
}
