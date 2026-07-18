<?php

namespace App\Integrations\Healthcare\Rpm;

/**
 * Canonical event types for remote-patient-monitoring feeds
 * (ACUM-PRD-HAH-001 §5.2). PascalCase values match the RTDC canonical
 * vocabulary (App\Rtdc\Events\CanonicalEvent).
 */
final class RpmEventVocabulary
{
    public const OBSERVATION_RECORDED = 'ObservationRecorded';

    public const DEVICE_STATUS_CHANGED = 'DeviceStatusChanged';

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return [self::OBSERVATION_RECORDED, self::DEVICE_STATUS_CHANGED];
    }
}
