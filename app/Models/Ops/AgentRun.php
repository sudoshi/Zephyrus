<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    protected $table = 'ops.agent_runs';

    protected $primaryKey = 'agent_run_id';

    protected $guarded = [];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'summary_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AgentDefinition::class, 'agent_definition_id', 'agent_definition_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class, 'agent_run_id', 'agent_run_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AgentApproval::class, 'agent_run_id', 'agent_run_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(AgentEvaluation::class, 'agent_run_id', 'agent_run_id');
    }

    public function safetyEvents(): HasMany
    {
        return $this->hasMany(AgentSafetyEvent::class, 'agent_run_id', 'agent_run_id');
    }
}
