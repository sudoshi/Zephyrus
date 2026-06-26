<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutcomeAttribution extends Model
{
    protected $table = 'ops.outcome_attribution';

    protected $primaryKey = 'outcome_attribution_id';

    protected $guarded = [];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'balancing_summary' => 'array',
        'caveats' => 'array',
        'comparison_options' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class, 'intervention_id', 'intervention_id');
    }
}
