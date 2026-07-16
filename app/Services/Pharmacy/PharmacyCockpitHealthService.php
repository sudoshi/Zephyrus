<?php

namespace App\Services\Pharmacy;

/**
 * One aggregate-only Pharmacy health contract for the Flow-domain Cockpit. It
 * composes, without changing, the workspace-owned Medication Flow Board health
 * seam so the house wall and Flow drill cannot invent new math or a second
 * cohort. Real-time signals (verification queue depth, oldest STAT age,
 * shortage-drug stockouts) are current; the sepsis-at-risk signal depends on
 * warehouse administration evidence and is freshness-qualified through
 * PharmacyAdministrationFreshnessService — a stale batch tail renders it
 * unknown, never a false success or failure (§8). No pharmacist, verifier, or
 * any user-level dimension exists anywhere in this contract (§13).
 */
final class PharmacyCockpitHealthService
{
    public function __construct(
        private readonly PharmacyFlowBoardService $flowBoard,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $operations = $this->flowBoard->cockpitHealth();
        $queue = $operations['verificationQueue'];
        $sepsis = $operations['sepsisAtRisk'];
        $stockouts = $operations['shortageStockouts'];
        $adminState = (string) $sepsis['administrationState'];
        // The sepsis clock stops on warehouse administration evidence: a stale
        // or absent tail must qualify the metric, not the real-time feeds.
        $sepsisSourceState = in_array($adminState, ['stale', 'unknown'], true)
            ? ($adminState === 'unknown' ? 'missing' : 'stale')
            : $operations['sourceState'];

        return [
            'state' => $operations['sourceState'],
            'sourceCutoffAt' => $operations['sourceCutoffAt'],
            'verificationQueue' => [
                'value' => $queue['depth'],
                'hourNormDepth' => $queue['hourNormDepth'],
                'oldestAgeMinutes' => $queue['oldestAgeMinutes'],
                'coverageState' => $operations['coverageState'],
                'sourceState' => $operations['sourceState'],
                'sourceCutoffAt' => $operations['sourceCutoffAt'],
                'sourceLabel' => $operations['sourceLabel'],
            ],
            'oldestStat' => [
                'value' => $operations['oldestStatAgeMinutes'],
                'sourceState' => $operations['sourceState'],
                'sourceCutoffAt' => $operations['sourceCutoffAt'],
                'sourceLabel' => $operations['sourceLabel'],
            ],
            'sepsisAtRisk' => [
                'value' => $sepsis['value'],
                'openBreaches' => $sepsis['openBreaches'],
                'openWarnings' => $sepsis['openWarnings'],
                'administrationState' => $adminState,
                'administrationCutoffAt' => $sepsis['administrationCutoffAt'],
                'sourceState' => $sepsisSourceState,
                'sourceCutoffAt' => $sepsis['administrationCutoffAt'] ?? $operations['sourceCutoffAt'],
                'sourceLabel' => 'Pharmacy administration warehouse',
            ],
            'shortageStockouts' => [
                'value' => $stockouts['stations'],
                'shortageOrders' => $stockouts['shortageOrders'],
                'sourceState' => $operations['sourceState'],
                'sourceCutoffAt' => $operations['sourceCutoffAt'],
                'sourceLabel' => $operations['sourceLabel'],
            ],
        ];
    }
}
