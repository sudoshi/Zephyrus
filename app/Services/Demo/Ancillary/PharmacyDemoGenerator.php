<?php

namespace App\Services\Demo\Ancillary;

use App\Services\Demo\DemoClock;

final class PharmacyDemoGenerator extends AbstractAncillaryDemoGenerator
{
    protected function department(): string
    {
        return 'rx';
    }

    protected function systemClass(): string
    {
        return 'pharmacy';
    }

    protected function scenarios(DemoClock $clock): array
    {
        return [
            $this->scenario($clock, 1, 25, 'stat', 'inpatient', [
                $this->event('RX_ORDERED', 25), $this->event('RX_QUEUE_IN', 24),
            ], ['route' => 'central'], true),
            $this->scenario($clock, 2, 65, 'first_dose', 'inpatient', [
                $this->event('RX_ORDERED', 65), $this->event('RX_VERIFIED', 52),
                $this->event('RX_PREP_COMPLETE', 40), $this->event('RX_DISPENSED', 30),
                $this->event('RX_DELIVERED', 20), $this->event('RX_ADMINISTERED', 10, 'warehouse'),
            ], ['route' => 'iv_room', 'preparation_branch' => 'iv']),
            $this->scenario($clock, 3, 55, 'discharge', 'inpatient', [
                $this->event('RX_ORDERED', 55), $this->event('RX_VERIFIED', 35),
                $this->event('RX_PREP_COMPLETE', 22), $this->event('RX_DISPENSED', 12),
                $this->event('RX_DELIVERED', 5),
            ], ['route' => 'discharge', 'discharge_blocking' => true]),
            $this->scenario($clock, 4, 45, 'routine', 'inpatient', [
                $this->event('RX_ORDERED', 45), $this->event('RX_VERIFIED', 38),
                $this->event('RX_DISPENSED', 22, 'secondary'), $this->event('RX_OVERRIDE', 15, 'secondary'),
                $this->event('RX_DISCREPANCY_OPEN', 10, 'secondary'),
            ], ['route' => 'adc']),
            $this->scenario($clock, 5, 35, 'urgent', 'inpatient', [
                $this->event('RX_ORDERED', 35), $this->event('RX_VERIFIED', 28),
                $this->event('RX_MISSING_DOSE', 20), $this->event('RX_PREP_COMPLETE', 10),
                $this->event('RX_DISPENSED', 5),
            ], ['route' => 'central']),
        ];
    }
}
