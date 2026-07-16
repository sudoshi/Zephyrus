<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Shared interval definitions for procedure suites.
 *
 * Perioperative and Interventional Radiology intentionally use this class for
 * the common FCOTS, turnover, utilization, and room-running arithmetic. Domain
 * adapters may add gates, cohorts, and labels, but may not redefine the clocks.
 */
final class SuiteMetricCalculator
{
    public const FCOTS_GRACE_MINUTES = 15;

    public const ROOM_RUNNING_START_HOUR = 7;

    public const ROOM_RUNNING_END_HOUR = 18;

    public const PERIOP_STAFFED_PRIME_MINUTES = 720;

    public const PERIOP_STAFFED_CORE_MINUTES = 600;

    public const PERIOP_STAFFED_EXTENDED_MINUTES = 900;

    public function __construct(private readonly OperationalIntervalCalculator $intervals) {}

    public function firstCaseOnTime(DateTimeInterface|string|null $scheduled, DateTimeInterface|string|null $actual): ?bool
    {
        if ($scheduled === null || $actual === null) {
            return null;
        }

        return $this->instant($actual)->lessThanOrEqualTo(
            $this->instant($scheduled)->addMinutes(self::FCOTS_GRACE_MINUTES)
        );
    }

    public function turnoverMinutes(DateTimeInterface|string|null $previousEnd, DateTimeInterface|string|null $nextStart): int|float|null
    {
        if ($previousEnd === null || $nextStart === null) {
            return null;
        }

        $from = $this->instant($previousEnd);
        $to = $this->instant($nextStart);
        if ($to->lessThan($from)) {
            return null;
        }

        $minutes = round(($to->getTimestamp() - $from->getTimestamp()) / 60, 2);

        return floor($minutes) === $minutes ? (int) $minutes : $minutes;
    }

    /**
     * @param  list<array{start:DateTimeInterface|string,end:DateTimeInterface|string}>  $available
     * @param  list<array{start:DateTimeInterface|string,end:DateTimeInterface|string}>  $occupied
     * @param  list<array{start:DateTimeInterface|string,end:DateTimeInterface|string}>  $plannedDowntime
     * @param  list<array{start:DateTimeInterface|string,end:DateTimeInterface|string}>  $unplannedDowntime
     * @return array<string,mixed>
     */
    public function utilization(
        array $available,
        array $occupied,
        array $plannedDowntime = [],
        array $unplannedDowntime = [],
    ): array {
        $result = $this->intervals->calculate($available, $occupied, $plannedDowntime, $unplannedDowntime);
        $result['utilizationPercent'] = $this->utilizationPercent($result['examMinutes'], $result['availableMinutes']);

        return $result;
    }

    public function utilizationPercent(int|float $occupiedMinutes, int|float $availableMinutes, ?float $cap = null): int|float|null
    {
        if ($availableMinutes <= 0 || $occupiedMinutes < 0) {
            return null;
        }
        $value = 100 * $occupiedMinutes / $availableMinutes;
        if ($cap !== null) {
            $value = min($cap, $value);
        }

        $rounded = round($value, 1);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    /**
     * @param  list<array{start:DateTimeInterface|string,end:DateTimeInterface|string}>  $occupied
     */
    public function roomsRunningAt(array $occupied, DateTimeInterface|string $instant): int
    {
        $at = $this->instant($instant);

        return collect($occupied)->filter(function (array $interval) use ($at): bool {
            $start = $this->instant($interval['start']);
            $end = $this->instant($interval['end']);

            return $start->lessThanOrEqualTo($at) && $end->greaterThan($at);
        })->count();
    }

    /** @param array<string,mixed> $metadata */
    public function isPlannedDowntime(string $status, string $reasonCode, array $metadata = []): bool
    {
        if (is_bool($metadata['planned'] ?? null)) {
            return $metadata['planned'];
        }
        $code = strtoupper($reasonCode);

        return $status === 'scheduled'
            || str_starts_with($code, 'PLANNED_')
            || str_starts_with($code, 'PREVENTIVE_')
            || str_starts_with($code, 'SCHEDULED_')
            || in_array($code, ['CALIBRATION', 'QUALITY_CONTROL'], true);
    }

    /** @return array<string,array{label:string,definition:string,authority:string}> */
    public function definitions(): array
    {
        return [
            'fcots' => [
                'label' => 'First-case on-time start',
                'definition' => 'The first scheduled case in a declared room-day is on time when actual procedure start is no later than scheduled start plus 15 minutes.',
                'authority' => self::class.'::firstCaseOnTime',
            ],
            'turnover' => [
                'label' => 'Room turnover',
                'definition' => 'Elapsed minutes from the prior completed room-occupancy interval to the next started interval in the same declared room; negative gaps are invalid.',
                'authority' => self::class.'::turnoverMinutes',
            ],
            'utilization' => [
                'label' => 'Suite utilization',
                'definition' => 'Unioned occupied minutes divided by explicitly declared available operating minutes. Downtime and overlapping activity cannot double count.',
                'authority' => self::class.'::utilizationPercent',
            ],
            'room_running' => [
                'label' => 'Rooms running',
                'definition' => 'Distinct declared rooms with an occupancy interval started at or before the sample instant and ending after it.',
                'authority' => self::class.'::roomsRunningAt',
            ],
            'downtime' => [
                'label' => 'Planned and unplanned downtime',
                'definition' => 'Explicit planned metadata wins; otherwise scheduled state and governed planned, preventive, calibration, or quality-control reason codes are planned. Remaining downtime is unplanned.',
                'authority' => self::class.'::isPlannedDowntime',
            ],
        ];
    }

    private function instant(DateTimeInterface|string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }
}
