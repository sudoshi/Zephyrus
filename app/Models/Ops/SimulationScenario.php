<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimulationScenario extends Model
{
    protected $table = 'ops.simulation_scenarios';

    protected $primaryKey = 'simulation_scenario_id';

    protected $guarded = [];

    protected $casts = [
        'intervention_payload' => 'array',
        'promoted_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SimulationRun::class, 'simulation_run_id', 'simulation_run_id');
    }

    public function promotedRecommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'promoted_recommendation_id', 'recommendation_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(SimulationResult::class, 'simulation_scenario_id', 'simulation_scenario_id');
    }
}
