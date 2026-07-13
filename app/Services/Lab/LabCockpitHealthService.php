<?php

namespace App\Services\Lab;

/**
 * One aggregate-only Laboratory health contract for the Flow Cockpit. It
 * composes the workspace-owned Flow Board and Decision-Pending services so
 * the house wall and drill cannot create a second cohort or expose results.
 */
final class LabCockpitHealthService
{
    public function __construct(
        private readonly LabFlowBoardService $flowBoard,
        private readonly LabDecisionPendingService $pending,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $operations = $this->flowBoard->cockpitHealth();
        $decisions = $this->pending->cockpitHealth();
        $pendingCount = (int) $decisions['pendingCount'];
        $verifiedEmpty = $pendingCount === 0 && $decisions['sourceState'] === 'missing';
        $decisionState = $verifiedEmpty ? $operations['sourceState'] : $decisions['sourceState'];
        $decisionCutoff = $verifiedEmpty ? $operations['sourceCutoffAt'] : $decisions['sourceCutoffAt'];
        $callbacks = $operations['criticalCallbacks'];
        $atRisk = collect($callbacks['byState'])->whereIn('state', ['pending_notification', 'notified', 'escalated'])->sum('count');

        return [
            'state' => $this->overallState([$operations['sourceState'], $decisionState]),
            'statCompliance' => [
                'value' => $operations['statCompliancePercent'],
                'statOrders' => $operations['statOrders'],
                'statCompliant' => $operations['statCompliant'],
                'coverageState' => $operations['coverageState'],
                'sourceState' => $operations['sourceState'],
                'sourceCutoffAt' => $operations['sourceCutoffAt'],
                'sourceLabel' => $operations['sourceLabel'],
            ],
            'oldestDecisionPending' => [
                'value' => $pendingCount === 0 && $decisionState === 'fresh' ? 0 : $decisions['oldestAgeMinutes'],
                'pendingCount' => $pendingCount,
                'byDecisionClass' => $decisions['byDecisionClass'],
                'sourceState' => $decisionState,
                'sourceCutoffAt' => $decisionCutoff,
                'sourceLabel' => $verifiedEmpty ? $operations['sourceLabel'] : $decisions['sourceLabel'],
            ],
            'criticalCallbacks' => [
                'value' => $callbacks['open'],
                'atRiskCount' => (int) $atRisk,
                'oldestOpenAgeMinutes' => $callbacks['oldestOpenAgeMinutes'],
                'byState' => $callbacks['byState'],
                'coverageState' => $operations['coverageState'],
                'sourceState' => $operations['sourceState'],
                'sourceCutoffAt' => $operations['sourceCutoffAt'],
                'sourceLabel' => $operations['sourceLabel'],
            ],
        ];
    }

    /** @param list<string> $states */
    private function overallState(array $states): string
    {
        foreach (['error', 'missing', 'stale', 'degraded'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'fresh';
    }
}
