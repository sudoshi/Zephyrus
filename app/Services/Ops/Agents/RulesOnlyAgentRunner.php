<?php

namespace App\Services\Ops\Agents;

use App\Models\Ops\AgentDefinition;
use App\Models\Ops\AgentRun;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class RulesOnlyAgentRunner implements AgentRunner
{
    public function run(AgentDefinition $definition, ?User $actor, string $objective, array $input, callable $planner): AgentRun
    {
        $run = AgentRun::create([
            'agent_run_uuid' => (string) Str::uuid(),
            'agent_definition_id' => $definition->agent_definition_id,
            'actor_user_id' => $actor?->id,
            'status' => 'running',
            'mode' => 'rules_only',
            'objective' => $objective,
            'input_payload' => $input,
            'output_payload' => [],
            'summary_payload' => [],
            'started_at' => now(),
        ]);

        try {
            $output = $planner($run);

            if ($run->fresh()->status === 'blocked') {
                return $run->fresh()->load(['definition', 'toolCalls', 'evaluations', 'safetyEvents']);
            }

            $run->fill([
                'status' => 'completed',
                'output_payload' => $output,
                'summary_payload' => $output['summary'] ?? [],
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $throwable) {
            $run->fill([
                'status' => 'failed',
                'blocked_reason' => $throwable->getMessage(),
                'output_payload' => [
                    'status' => 'failed',
                    'error' => $throwable->getMessage(),
                ],
                'completed_at' => now(),
            ])->save();
        }

        return $run->fresh()->load(['definition', 'toolCalls', 'evaluations', 'safetyEvents']);
    }
}
