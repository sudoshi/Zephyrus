<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BedPlacementDecision extends Model
{
    protected $table = 'prod.bed_placement_decisions';

    protected $primaryKey = 'bed_placement_decision_id';

    protected $fillable = [
        'bed_request_id', 'recommended_bed_id', 'chosen_bed_id',
        'action', 'reason', 'score_snapshot', 'decided_by',
    ];

    protected $casts = [
        'score_snapshot' => 'array',
    ];

    public function bedRequest(): BelongsTo
    {
        return $this->belongsTo(BedRequest::class, 'bed_request_id', 'bed_request_id');
    }
}
