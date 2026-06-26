<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentEvaluation extends Model
{
    protected $table = 'ops.agent_evaluations';

    protected $primaryKey = 'agent_evaluation_id';

    protected $guarded = [];

    protected $casts = [
        'score' => 'decimal:2',
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id', 'agent_run_id');
    }
}
