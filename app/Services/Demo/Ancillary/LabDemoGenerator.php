<?php

namespace App\Services\Demo\Ancillary;

use App\Services\Demo\DemoClock;

final class LabDemoGenerator extends AbstractAncillaryDemoGenerator
{
    protected function department(): string
    {
        return 'lab';
    }

    protected function systemClass(): string
    {
        return 'lis';
    }

    protected function scenarios(DemoClock $clock): array
    {
        return [
            $this->scenario($clock, 1, 90, 'stat', 'emergency', [
                $this->event('LAB_ORDERED', 90), $this->event('LAB_COLLECTED', 80),
            ], ['test_family' => 'troponin'], true),
            $this->scenario($clock, 2, 55, 'routine', 'inpatient', [
                $this->event('LAB_ORDERED', 55), $this->event('LAB_COLLECTED', 48),
                $this->event('LAB_RECEIVED', 40), $this->event('LAB_RESULTED', 15, 'secondary'),
                $this->event('LAB_VERIFIED', 10),
            ], ['test_family' => 'chemistry']),
            $this->scenario($clock, 3, 75, 'urgent', 'inpatient', [
                $this->event('LAB_ORDERED', 75), $this->event('LAB_COLLECTED', 68),
                $this->event('LAB_REJECTED', 55), $this->event('LAB_RECOLLECT_ORDERED', 50),
                $this->event('LAB_COLLECTED', 40, 'secondary'), $this->event('LAB_VERIFIED', 8),
            ], ['test_family' => 'hematology']),
            $this->scenario($clock, 4, 50, 'stat', 'inpatient', [
                $this->event('LAB_ORDERED', 50), $this->event('LAB_RESULTED', 20),
                $this->event('LAB_VERIFIED', 18), $this->event('LAB_CRITICAL_NOTIFIED', 10),
                $this->event('LAB_CRITICAL_ACKED', 5),
            ], ['test_family' => 'potassium']),
            $this->scenario($clock, 5, 35, 'routine', 'outpatient', [
                $this->event('LAB_ORDERED', 35), $this->event('LAB_RESULTED', 5),
            ], ['test_family' => 'chemistry']),
        ];
    }
}
