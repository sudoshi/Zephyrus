<?php

namespace App\Models\CarePathways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PathwayApproval extends Model
{
    protected $table = 'care_pathways.approvals';

    protected $primaryKey = 'pathway_approval_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'effective_start' => 'immutable_datetime',
        'effective_end' => 'immutable_datetime',
        'decided_at' => 'immutable_datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'approval_uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PathwayVersion::class, 'pathway_version_id');
    }
}
