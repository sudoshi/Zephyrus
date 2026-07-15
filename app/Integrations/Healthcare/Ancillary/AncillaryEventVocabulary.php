<?php

namespace App\Integrations\Healthcare\Ancillary;

use InvalidArgumentException;

final class AncillaryEventVocabulary
{
    /** @var array<string, list<string>> */
    private const CODES = [
        'rad' => [
            'RAD_ORDERED', 'RAD_PROTOCOLLED', 'RAD_SCHEDULED', 'RAD_PREP_COMPLETE',
            'RAD_TRANSPORT_REQUESTED', 'RAD_TRANSPORT_COMPLETE', 'RAD_EXAM_START',
            'RAD_EXAM_END', 'RAD_IMAGES_AVAILABLE', 'RAD_PRELIM', 'RAD_FINAL',
            'RAD_CRITICAL_NOTIFIED', 'RAD_CRITICAL_ACKED', 'RAD_FOLLOWUP_TRACKED', 'RAD_CANCELLED',
        ],
        'lab' => [
            'LAB_ORDERED', 'LAB_COLLECTED', 'LAB_IN_TRANSIT', 'LAB_RECEIVED',
            'LAB_ANALYSIS_STARTED', 'LAB_PRELIM', 'LAB_RESULTED', 'LAB_VERIFIED',
            'LAB_CRITICAL_NOTIFIED', 'LAB_CRITICAL_ACKED', 'LAB_REJECTED',
            'LAB_RECOLLECT_ORDERED', 'LAB_CORRECTED', 'LAB_CANCELLED',
        ],
        'pathology' => [
            'AP_SPECIMEN_OUT', 'AP_RECEIVED', 'AP_GROSSED', 'AP_PROCESSING_BATCH',
            'AP_SLIDES_READY', 'AP_DIAGNOSED', 'AP_SIGNED_OUT', 'AP_FROZEN_STARTED',
            'AP_FROZEN_RESULTED',
        ],
        'blood_bank' => [
            'BB_ORDERED', 'BB_TNS_READY', 'BB_CROSSMATCH_READY', 'BB_UNIT_ISSUED',
            'BB_MTP_ACTIVATED', 'BB_MTP_CLOSED',
        ],
        'rx' => [
            'RX_ORDERED', 'RX_QUEUE_IN', 'RX_VERIFIED', 'RX_PREP_STARTED',
            'RX_PREP_COMPLETE', 'RX_CHECKED', 'RX_DISPENSED', 'RX_DELIVERED',
            'RX_ADMINISTERED', 'RX_MISSING_DOSE', 'RX_RETURNED', 'RX_WASTED',
            'RX_OVERRIDE', 'RX_DISCREPANCY_OPEN', 'RX_DISCREPANCY_RESOLVED', 'RX_DISCONTINUED',
        ],
    ];

    private const SUFFIX_OVERRIDES = [
        'RAD_ORDERED' => 'order_placed',
        'RAD_EXAM_START' => 'exam_started',
        'RAD_EXAM_END' => 'exam_completed',
        'RAD_FINAL' => 'report_finalized',
        'LAB_ORDERED' => 'order_placed',
        'LAB_COLLECTED' => 'specimen_collected',
        'LAB_RESULTED' => 'result_reported',
        'LAB_VERIFIED' => 'result_verified',
        'AP_SIGNED_OUT' => 'report_signed_out',
        'BB_ORDERED' => 'request_ordered',
        'RX_ORDERED' => 'order_placed',
        'RX_QUEUE_IN' => 'queue_entered',
    ];

    private const EVENT_DEPARTMENTS = [
        'rad' => 'radiology',
        'lab' => 'lab',
        'pathology' => 'pathology',
        'blood_bank' => 'blood_bank',
        'rx' => 'pharmacy',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_values(array_merge(...array_values(self::CODES)));
    }

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return array_map(self::eventTypeFor(...), self::codes());
    }

    public static function departmentFor(string $milestoneCode): string
    {
        foreach (self::CODES as $department => $codes) {
            if (in_array($milestoneCode, $codes, true)) {
                return $department;
            }
        }

        throw new InvalidArgumentException("Unknown ancillary milestone code [{$milestoneCode}].");
    }

    public static function eventTypeFor(string $milestoneCode): string
    {
        $department = self::departmentFor($milestoneCode);
        $prefix = match ($department) {
            'rad' => 'RAD_',
            'lab' => 'LAB_',
            'pathology' => 'AP_',
            'blood_bank' => 'BB_',
            'rx' => 'RX_',
        };
        $suffix = self::SUFFIX_OVERRIDES[$milestoneCode]
            ?? strtolower(substr($milestoneCode, strlen($prefix)));

        return 'ancillary.'.self::EVENT_DEPARTMENTS[$department].'.'.$suffix;
    }

    public static function milestoneCodeFor(string $eventType): string
    {
        $code = array_search($eventType, array_combine(self::codes(), self::eventTypes()), true);
        if (! is_string($code)) {
            throw new InvalidArgumentException("Unknown ancillary canonical event type [{$eventType}].");
        }

        return $code;
    }
}
