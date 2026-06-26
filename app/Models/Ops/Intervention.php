<?php

namespace App\Models\Ops;

use App\Models\PdsaCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Intervention extends Model
{
    protected $table = 'ops.interventions';

    protected $primaryKey = 'intervention_id';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'baseline_started_at' => 'datetime',
        'baseline_ended_at' => 'datetime',
        'followup_started_at' => 'datetime',
        'followup_ended_at' => 'datetime',
        'evidence_payload' => 'array',
        'stratification_payload' => 'array',
    ];

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'recommendation_id', 'recommendation_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(OperationalAction::class, 'action_id', 'action_id');
    }

    public function pdsaCycle(): BelongsTo
    {
        return $this->belongsTo(PdsaCycle::class, 'pdsa_cycle_id', 'pdsa_cycle_id');
    }

    public function simulationScenario(): BelongsTo
    {
        return $this->belongsTo(SimulationScenario::class, 'simulation_scenario_id', 'simulation_scenario_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(InterventionMetric::class, 'intervention_id', 'intervention_id');
    }

    public function attribution(): HasOne
    {
        return $this->hasOne(OutcomeAttribution::class, 'intervention_id', 'intervention_id');
    }
}
