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
        'ok_edge' => 'decimal:4',
        'warn_edge' => 'decimal:4',
        'crit_edge' => 'decimal:4',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Direction-aware band edges for the StatusEngine (Zephyrus 2.0 P0).
     * `watch_band_pct` comes from metadata (the watch tier fires within this
     * proximity of the warn edge — the band the cockpit spec under-specifies).
     *
     * @return array{direction: string, ok: ?float, warn: ?float, crit: ?float, watch_band_pct: float}
     */
    public function edges(): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'direction' => $this->direction ?? 'neutral',
            'ok' => $this->ok_edge !== null ? (float) $this->ok_edge : null,
            'warn' => $this->warn_edge !== null ? (float) $this->warn_edge : null,
            'crit' => $this->crit_edge !== null ? (float) $this->crit_edge : null,
            'watch_band_pct' => (float) ($metadata['watch_band_pct'] ?? \App\Services\Cockpit\StatusEngine::DEFAULT_WATCH_BAND_PCT),
        ];
    }

    public function lineage(): HasMany
    {
        return $this->hasMany(MetricLineage::class, 'metric_definition_id', 'metric_definition_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(MetricValue::class, 'metric_definition_id', 'metric_definition_id');
    }
}
