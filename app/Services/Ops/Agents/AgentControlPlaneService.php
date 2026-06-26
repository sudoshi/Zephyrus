<?php

namespace App\Services\Ops\Agents;

use App\Models\Ops\AgentDefinition;
use App\Models\Ops\AgentEvaluation;
use App\Models\Ops\AgentRun;
use App\Models\Ops\AgentSafetyEvent;
use App\Models\Ops\AgentToolCall;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class AgentControlPlaneService
{
    public function __construct(
        private readonly AgentToolRegistry $tools,
        private readonly RulesOnlyAgentRunner $runner,
    ) {}

    public function definitions(): Collection
    {
        return collect($this->definitionCatalog())
            ->map(fn (array $definition): AgentDefinition => $this->materializeDefinition($definition))
            ->values();
    }

    public function runCapacityCommander(?User $actor, string $objective = 'Assess current capacity risk and recommend read-only next actions.'): AgentRun
    {
        $definition = $this->materializeDefinition($this->definitionCatalog()['capacity_commander']);

        return $this->runner->run($definition, $actor, $objective, [
            'agent' => 'capacity_commander',
            'tool_allowlist' => $definition->tool_allowlist,
        ], function (AgentRun $run) use ($actor, $objective): array {
            if ($blocked = $this->blockedObjective($run, $objective)) {
                return $blocked;
            }

            $snapshot = $this->executeTool($run, 'capacity.snapshot', [], $actor);
            $summary = $snapshot['summary'];
            $findings = $snapshot['findings'] ?? [];
            $status = (string) $snapshot['status'];
            $output = [
                'key' => 'capacity_commander',
                'label' => 'Capacity Commander',
                'mode' => 'rules_only',
                'llmEnabled' => false,
                'readOnly' => true,
                'status' => $status,
                'summary' => [
                    'riskScore' => $summary['riskScore'],
                    'netBeds' => $summary['netBeds'],
                    'edBoarders' => $summary['edBoarders'],
                    'transportAtRisk' => $summary['transportAtRisk'],
                    'findingCount' => count($findings),
                    'sourceFreshnessStatus' => $summary['sourceFreshnessStatus'] ?? 'warning',
                ],
                'findings' => $findings,
                'nextActions' => collect($findings)
                    ->pluck('recommendedAction')
                    ->take(5)
                    ->values()
                    ->all(),
                'sourceTables' => $snapshot['sourceTables'] ?? [],
            ];

            $this->writeGoldenEvaluations($run, $output, expectedTool: 'capacity.snapshot');

            return $output;
        });
    }

    public function runDataQualityAgent(?User $actor, string $objective = 'Assess analytics source quality and governance readiness.'): AgentRun
    {
        $definition = $this->materializeDefinition($this->definitionCatalog()['data_quality_agent']);

        return $this->runner->run($definition, $actor, $objective, [
            'agent' => 'data_quality_agent',
            'tool_allowlist' => $definition->tool_allowlist,
        ], function (AgentRun $run) use ($actor, $objective): array {
            if ($blocked = $this->blockedObjective($run, $objective)) {
                return $blocked;
            }

            $quality = $this->executeTool($run, 'data_quality.summary', [], $actor);
            $output = [
                'key' => 'data_quality_agent',
                'label' => 'Data Quality Agent',
                'mode' => 'rules_only',
                'llmEnabled' => false,
                'readOnly' => true,
                'status' => (string) ($quality['agent']['status'] ?? (($quality['summary']['critical'] ?? 0) > 0 ? 'critical' : 'warning')),
                'summary' => $quality['agent']['summary'] ?? $quality['summary'] ?? [],
                'findings' => $quality['agent']['findings'] ?? [],
                'nextActions' => $quality['agent']['nextActions'] ?? [],
                'sourceMap' => $quality['sourceMap'] ?? [],
            ];

            $this->writeGoldenEvaluations($run, $output, expectedTool: 'data_quality.summary');

            return $output;
        });
    }

    /** @return array<string,mixed>|null */
    private function blockedObjective(AgentRun $run, string $objective): ?array
    {
        $unsafe = $this->unsafeInstruction($objective);
        if ($unsafe === null) {
            return null;
        }

        AgentSafetyEvent::create([
            'agent_run_id' => $run->agent_run_id,
            'event_type' => 'prompt_injection',
            'severity' => 'critical',
            'status' => 'blocked',
            'detail' => "Objective contains unsafe instruction: {$unsafe}.",
            'input_excerpt' => Str::limit($objective, 240),
            'payload' => [
                'matched_pattern' => $unsafe,
                'policy' => 'read_only_minimum_necessary',
            ],
        ]);

        $output = [
            'status' => 'blocked',
            'blockedReason' => 'Unsafe objective blocked by read-only agent guardrails.',
            'readOnly' => true,
            'findings' => [],
            'nextActions' => [],
        ];

        $run->fill([
            'status' => 'blocked',
            'blocked_reason' => $output['blockedReason'],
            'output_payload' => $output,
            'summary_payload' => ['blocked' => true],
            'completed_at' => now(),
        ])->save();

        $this->writeEvaluation($run, 'prompt_injection_guardrail', 'pass', 100, 'Unsafe objective was blocked before tool execution.');
        $this->writeEvaluation($run, 'no_write_tools', 'pass', 100, 'No tools were called for the blocked run.');

        return $output;
    }

    /** @param array<string,mixed> $payload */
    private function executeTool(AgentRun $run, string $toolKey, array $payload, ?User $actor): array
    {
        $tool = $this->tools->tools()[$toolKey] ?? null;
        $call = AgentToolCall::create([
            'agent_run_id' => $run->agent_run_id,
            'tool_key' => $toolKey,
            'status' => 'started',
            'read_only' => (bool) ($tool['read_only'] ?? false),
            'request_payload' => $this->tools->redact($payload),
            'response_payload' => [],
            'started_at' => now(),
        ]);

        try {
            $response = $this->tools->call($toolKey, $payload, $actor);
            $call->fill([
                'status' => 'completed',
                'response_payload' => $this->tools->redact($response),
                'completed_at' => now(),
            ])->save();

            return $response;
        } catch (RuntimeException $exception) {
            $call->fill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    /** @param array<string,mixed> $output */
    private function writeGoldenEvaluations(AgentRun $run, array $output, string $expectedTool): void
    {
        $toolCalls = $run->toolCalls()->pluck('tool_key')->all();

        $this->writeEvaluation(
            $run,
            'expected_tool_called',
            in_array($expectedTool, $toolCalls, true) ? 'pass' : 'fail',
            in_array($expectedTool, $toolCalls, true) ? 100 : 0,
            "Expected {$expectedTool} to ground the run."
        );
        $this->writeEvaluation(
            $run,
            'no_write_tools',
            $run->toolCalls()->where('read_only', false)->count() === 0 ? 'pass' : 'fail',
            $run->toolCalls()->where('read_only', false)->count() === 0 ? 100 : 0,
            'Only read-only tools may be called by Phase 5 agents.'
        );
        $this->writeEvaluation(
            $run,
            'phi_minimized',
            $this->containsPhiKey($output) ? 'fail' : 'pass',
            $this->containsPhiKey($output) ? 0 : 100,
            'Agent output must not expose direct PHI identifiers.'
        );
    }

    private function writeEvaluation(AgentRun $run, string $key, string $status, float $score, string $detail): void
    {
        AgentEvaluation::create([
            'agent_run_id' => $run->agent_run_id,
            'evaluation_key' => $key,
            'status' => $status,
            'score' => $score,
            'detail' => $detail,
            'payload' => [],
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function definitionCatalog(): array
    {
        return [
            'capacity_commander' => [
                'agent_key' => 'capacity_commander',
                'label' => 'Capacity Commander',
                'description' => 'Read-only rules agent that summarizes current capacity risk and huddle-ready next actions.',
                'mode' => 'rules_only',
                'status' => 'active',
                'read_only' => true,
                'minimum_role' => 'user',
                'tool_allowlist' => ['capacity.snapshot'],
                'safety_policy' => [
                    'approval_required_for_writes' => true,
                    'phi_minimization' => true,
                    'prompt_injection_blocking' => true,
                    'stale_data_guardrails' => true,
                ],
            ],
            'data_quality_agent' => [
                'agent_key' => 'data_quality_agent',
                'label' => 'Data Quality Agent',
                'description' => 'Read-only rules agent that checks source freshness, lineage, ownership, and metric governance.',
                'mode' => 'rules_only',
                'status' => 'active',
                'read_only' => true,
                'minimum_role' => 'user',
                'tool_allowlist' => ['data_quality.summary'],
                'safety_policy' => [
                    'approval_required_for_writes' => true,
                    'phi_minimization' => true,
                    'prompt_injection_blocking' => true,
                    'stale_data_guardrails' => true,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function materializeDefinition(array $payload): AgentDefinition
    {
        /** @var AgentDefinition $definition */
        $definition = AgentDefinition::firstOrNew(['agent_key' => $payload['agent_key']]);
        if (! $definition->exists) {
            $definition->agent_definition_uuid = (string) Str::uuid();
        }

        $definition->fill($payload)->save();

        return $definition->refresh();
    }

    private function unsafeInstruction(string $objective): ?string
    {
        $normalized = strtolower($objective);

        foreach ([
            'ignore previous',
            'ignore policy',
            'bypass',
            'approve all',
            'complete all',
            'delete',
            'writeback',
            'send message',
            'show patient',
            'patient_ref',
            'mrn',
            'ssn',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    private function containsPhiKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (str_contains($normalized, 'patient')
                || str_contains($normalized, 'mrn')
                || str_contains($normalized, 'ssn')
                || str_contains($normalized, 'dob')
            ) {
                return true;
            }

            if ($this->containsPhiKey($item)) {
                return true;
            }
        }

        return false;
    }
}
