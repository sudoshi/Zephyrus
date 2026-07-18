<?php

namespace App\Models\Cockpit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Open/clear alert lifecycle rows (Zephyrus 2.0 P1 table; the P6 AlertEngine
 * reconciles these from StatusEngine warn/crit MetricValues — alerts are a
 * DERIVATION, never hand-set). hold_count carries flap-damping state.
 */
class CockpitAlert extends Model
{
    protected $table = 'prod.cockpit_alerts';

    protected $primaryKey = 'cockpit_alert_id';

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'cleared_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'hold_count' => 'integer',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }
}
