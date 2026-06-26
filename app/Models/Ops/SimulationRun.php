<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimulationRun extends Model
{
    protected $table = 'ops.simulation_runs';

    protected $primaryKey = 'simulation_run_id';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'baseline_payload' => 'array',
        'summary_payload' => 'array',
    ];

    public function baselineSnapshot(): BelongsTo
    {
        return $this->belongsTo(StateSnapshot::class, 'baseline_snapshot_id', 'state_snapshot_id');
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(SimulationScenario::class, 'simulation_run_id', 'simulation_run_id');
    }
}
