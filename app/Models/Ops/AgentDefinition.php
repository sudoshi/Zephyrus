<?php

namespace App\Models\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentDefinition extends Model
{
    protected $table = 'ops.agent_definitions';

    protected $primaryKey = 'agent_definition_id';

    protected $guarded = [];

    protected $casts = [
        'read_only' => 'boolean',
        'tool_allowlist' => 'array',
        'safety_policy' => 'array',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'agent_definition_id', 'agent_definition_id');
    }
}
