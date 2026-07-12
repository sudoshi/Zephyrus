<?php

namespace App\Services\Radiology;

use Carbon\CarbonImmutable;

/**
 * One aggregate-only Radiology health contract for the Flow-domain Cockpit.
 * It composes, without changing, the workspace-owned Flow Board and Reads
 * health seams so snapshot tiles and the Flow drill cannot invent new math.
 */
final class RadiologyCockpitHealthService
{
    public function __construct(
        private readonly RadiologyFlowBoardService $flowBoard,
        private readonly RadiologyReadsService $reads,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $operations = $this->flowBoard->cockpitHealth();
        $reads = $this->reads->cockpitHealth();
        $cutoffs = array_values(array_filter([
            $operations['sourceCutoffAt'],
            $operations['scannerSourceCutoffAt'],
            $reads['sourceCutoffAt'],
        ]));

        return [
            'state' => $this->overallState([$operations['sourceState'], $reads['sourceState']]),
            'sourceCutoffAt' => $cutoffs === [] ? null : collect($cutoffs)
                ->map(fn (string $cutoff): CarbonImmutable => CarbonImmutable::parse($cutoff))
                ->max()?->toAtomString(),
            'openBreaches' => [
                'value' => $operations['openBreaches'],
                'sourceState' => $operations['sourceState'],
                'sourceCutoffAt' => $operations['sourceCutoffAt'],
                'sourceLabel' => 'Radiology operational feeds',
            ],
            'oldestUnread' => [
                'value' => $reads['oldestUnreadAgeMinutes'] ?? ($reads['unreadCount'] === 0 ? 0 : null),
                'unreadCount' => $reads['unreadCount'],
                'byPriority' => $reads['unreadByPriority'],
                'sourceState' => $reads['sourceState'],
                'sourceCutoffAt' => $reads['sourceCutoffAt'],
                'sourceLabel' => 'Radiology reporting feeds',
            ],
            'scannersDown' => [
                'value' => $operations['scannersDown'],
                'scannerTotal' => $operations['scannerTotal'],
                'sourceState' => $operations['sourceState'],
                'sourceCutoffAt' => $operations['scannerSourceCutoffAt'] ?? $operations['sourceCutoffAt'],
                'sourceLabel' => 'Radiology scanner inventory',
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
