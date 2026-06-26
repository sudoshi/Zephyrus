<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricLineage extends Model
{
    protected $table = 'ops.metric_lineage';

    protected $primaryKey = 'metric_lineage_id';

    protected $guarded = [];

    protected $casts = [
        'confidence_weight' => 'decimal:4',
        'source_filter' => 'array',
        'metadata' => 'array',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetricDefinition::class, 'metric_definition_id', 'metric_definition_id');
    }
}
