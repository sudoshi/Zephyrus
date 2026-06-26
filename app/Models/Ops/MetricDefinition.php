<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetricDefinition extends Model
{
    protected $table = 'ops.metric_definitions';

    protected $primaryKey = 'metric_definition_id';

    protected $guarded = [];

    protected $casts = [
        'target_value' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function lineage(): HasMany
    {
        return $this->hasMany(MetricLineage::class, 'metric_definition_id', 'metric_definition_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(MetricValue::class, 'metric_definition_id', 'metric_definition_id');
    }
}
