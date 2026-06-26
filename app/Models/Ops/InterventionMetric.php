<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterventionMetric extends Model
{
    protected $table = 'ops.intervention_metrics';

    protected $primaryKey = 'intervention_metric_id';

    protected $guarded = [];

    protected $casts = [
        'baseline_value' => 'decimal:4',
        'followup_value' => 'decimal:4',
        'delta_value' => 'decimal:4',
        'delta_pct' => 'decimal:4',
        'is_primary' => 'boolean',
        'baseline_started_at' => 'datetime',
        'baseline_ended_at' => 'datetime',
        'followup_started_at' => 'datetime',
        'followup_ended_at' => 'datetime',
        'source_payload' => 'array',
    ];

    public function intervention(): BelongsTo
    {
        return $this->belongsTo(Intervention::class, 'intervention_id', 'intervention_id');
    }
}
