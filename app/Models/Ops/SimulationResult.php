<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimulationResult extends Model
{
    protected $table = 'ops.simulation_results';

    protected $primaryKey = 'simulation_result_id';

    protected $guarded = [];

    protected $casts = [
        'baseline_value' => 'decimal:4',
        'projected_value' => 'decimal:4',
        'delta_value' => 'decimal:4',
        'result_payload' => 'array',
    ];

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id', 'simulation_scenario_id');
    }
}
