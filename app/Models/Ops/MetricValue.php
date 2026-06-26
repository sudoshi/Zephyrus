<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricValue extends Model
{
    protected $table = 'ops.metric_values';

    protected $primaryKey = 'metric_value_id';

    protected $guarded = [];

    protected $casts = [
        'measured_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'value' => 'decimal:4',
        'dimension_payload' => 'array',
        'metadata' => 'array',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetricDefinition::class, 'metric_definition_id', 'metric_definition_id');
    }
}
