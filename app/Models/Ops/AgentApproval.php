<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentApproval extends Model
{
    protected $table = 'ops.agent_approvals';

    protected $primaryKey = 'agent_approval_id';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id', 'agent_run_id');
    }
}
