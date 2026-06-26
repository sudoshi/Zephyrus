<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentToolCall extends Model
{
    protected $table = 'ops.agent_tool_calls';

    protected $primaryKey = 'agent_tool_call_id';

    protected $guarded = [];

    protected $casts = [
        'read_only' => 'boolean',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id', 'agent_run_id');
    }
}
