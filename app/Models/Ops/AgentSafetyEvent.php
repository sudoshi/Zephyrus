<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSafetyEvent extends Model
{
    protected $table = 'ops.agent_safety_events';

    protected $primaryKey = 'agent_safety_event_id';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id', 'agent_run_id');
    }
}
