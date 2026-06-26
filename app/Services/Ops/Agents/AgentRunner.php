<?php

namespace App\Services\Ops\Agents;

use App\Models\Ops\AgentDefinition;
use App\Models\Ops\AgentRun;
use App\Models\User;

interface AgentRunner
{
    /**
     * @param  array<string,mixed>  $input
     * @param  callable(AgentRun): array<string,mixed>  $planner
     */
    public function run(AgentDefinition $definition, ?User $actor, string $objective, array $input, callable $planner): AgentRun;
}
