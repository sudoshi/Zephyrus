<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Partitions declared operating windows into mutually exclusive operational
 * states. Downtime takes precedence over exam activity so overlapping source
 * records can never inflate the denominator or any numerator.
 */
final class OperationalIntervalCalculator
{
    private const PRECEDENCE = ['unplanned_downtime', 'planned_downtime', 'exam'];

    /**
     * @param  list<array{start: DateTimeInterface|string, end: DateTimeInterface|string}>  $available
     * @param  list<array{start: DateTimeInterface|string, end: DateTimeInterface|string}>  $exams
     * @param  list<array{start: DateTimeInterface|string, end: DateTimeInterface|string}>  $plannedDowntime
     * @param  list<array{start: DateTimeInterface|string, end: DateTimeInterface|string}>  $unplannedDowntime
     * @return array{
     *   availableMinutes: float,
     *   examMinutes: float,
     *   plannedDowntimeMinutes: float,
     *   unplannedDowntimeMinutes: float,
     *   idleMinutes: float,
     *   reconciliationDeltaMinutes: float,
     *   segments: list<array{start: CarbonImmutable, end: CarbonImmutable, type: string, minutes: float}>
     * }
     */
    public function calculate(
        array $available,
        array $exams = [],
        array $plannedDowntime = [],
        array $unplannedDowntime = [],
    ): array {
        $available = $this->union($available);
        $categories = [
            'exam' => $this->union($exams),
            'planned_downtime' => $this->union($plannedDowntime),
            'unplanned_downtime' => $this->union($unplannedDowntime),
        ];
        $segments = [];

        foreach ($available as $window) {
            $boundaries = [$window['start']->getTimestamp(), $window['end']->getTimestamp()];
            foreach ($categories as $intervals) {
                foreach ($intervals as $interval) {
                    $start = max($window['start']->getTimestamp(), $interval['start']->getTimestamp());
                    $end = min($window['end']->getTimestamp(), $interval['end']->getTimestamp());
                    if ($end > $start) {
                        $boundaries[] = $start;
                        $boundaries[] = $end;
                    }
                }
            }

            $boundaries = array_values(array_unique($boundaries));
            sort($boundaries, SORT_NUMERIC);
            for ($index = 0, $last = count($boundaries) - 1; $index < $last; $index++) {
                $start = $boundaries[$index];
                $end = $boundaries[$index + 1];
                if ($end <= $start) {
                    continue;
                }
                $type = 'idle';
                foreach (self::PRECEDENCE as $candidate) {
                    if ($this->overlaps($categories[$candidate], $start, $end)) {
                        $type = $candidate;
                        break;
                    }
                }
                $this->appendSegment($segments, $start, $end, $type);
            }
        }

        $totals = ['exam' => 0, 'planned_downtime' => 0, 'unplanned_downtime' => 0, 'idle' => 0];
        foreach ($segments as $segment) {
            $totals[$segment['type']] += $segment['end']->getTimestamp() - $segment['start']->getTimestamp();
        }
        $availableSeconds = array_sum(array_map(
            fn (array $interval): int => $interval['end']->getTimestamp() - $interval['start']->getTimestamp(),
            $available,
        ));
        $partitionSeconds = array_sum($totals);

        return [
            'availableMinutes' => $this->minutes($availableSeconds),
            'examMinutes' => $this->minutes($totals['exam']),
            'plannedDowntimeMinutes' => $this->minutes($totals['planned_downtime']),
            'unplannedDowntimeMinutes' => $this->minutes($totals['unplanned_downtime']),
            'idleMinutes' => $this->minutes($totals['idle']),
            'reconciliationDeltaMinutes' => $this->minutes($availableSeconds - $partitionSeconds),
            'segments' => $segments,
        ];
    }

    /**
     * @param  list<array{start: DateTimeInterface|string, end: DateTimeInterface|string}>  $intervals
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function union(array $intervals): array
    {
        $normalized = array_map(function (array $interval): array {
            $start = $this->instant($interval['start'] ?? null);
            $end = $this->instant($interval['end'] ?? null);
            if ($end->lessThanOrEqualTo($start)) {
                throw new InvalidArgumentException('Operational intervals require end after start.');
            }

            return ['start' => $start, 'end' => $end];
        }, $intervals);
        usort($normalized, fn (array $left, array $right): int => $left['start']->getTimestamp() <=> $right['start']->getTimestamp());

        $merged = [];
        foreach ($normalized as $interval) {
            $last = array_key_last($merged);
            if ($last === null || $interval['start']->greaterThan($merged[$last]['end'])) {
                $merged[] = $interval;

                continue;
            }
            if ($interval['end']->greaterThan($merged[$last]['end'])) {
                $merged[$last]['end'] = $interval['end'];
            }
        }

        return $merged;
    }

    /** @param list<array{start: CarbonImmutable, end: CarbonImmutable}> $intervals */
    private function overlaps(array $intervals, int $start, int $end): bool
    {
        foreach ($intervals as $interval) {
            if ($interval['start']->getTimestamp() < $end && $interval['end']->getTimestamp() > $start) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{start: CarbonImmutable, end: CarbonImmutable, type: string, minutes: float}> $segments */
    private function appendSegment(array &$segments, int $start, int $end, string $type): void
    {
        $last = array_key_last($segments);
        if ($last !== null && $segments[$last]['type'] === $type && $segments[$last]['end']->getTimestamp() === $start) {
            $segments[$last]['end'] = CarbonImmutable::createFromTimestampUTC($end);
            $segments[$last]['minutes'] = $this->minutes($segments[$last]['end']->getTimestamp() - $segments[$last]['start']->getTimestamp());

            return;
        }
        $segments[] = [
            'start' => CarbonImmutable::createFromTimestampUTC($start),
            'end' => CarbonImmutable::createFromTimestampUTC($end),
            'type' => $type,
            'minutes' => $this->minutes($end - $start),
        ];
    }

    private function instant(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface || is_string($value)) {
            return CarbonImmutable::parse($value)->utc();
        }

        throw new InvalidArgumentException('Operational interval bounds must be timestamps.');
    }

    private function minutes(int|float $seconds): int|float
    {
        $minutes = round($seconds / 60, 2);

        return floor($minutes) === $minutes ? (int) $minutes : $minutes;
    }
}
