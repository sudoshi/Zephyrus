<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

/** Resolves deployment-owned weekly operating-hours contracts into UTC windows. */
final class OperatingWindowResolver
{
    public function __construct(private readonly OperationalIntervalCalculator $intervals) {}

    /**
     * @param  array<string,mixed>|null  $contract
     * @return list<array{start:CarbonImmutable,end:CarbonImmutable}>
     */
    public function resolve(
        ?array $contract,
        string $dateFrom,
        string $dateTo,
        string $startTime = '00:00',
        string $endTime = '23:59',
    ): array {
        $weekly = is_array($contract['weekly'] ?? null) ? $contract['weekly'] : null;
        if ($weekly === null) {
            return [];
        }
        $timezone = is_string($contract['timezone'] ?? null)
            ? $contract['timezone']
            : (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, $timezone);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, $timezone);
        if ($from === false || $to === false || $to->lessThan($from)) {
            return [];
        }

        $windows = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $filterStart = $this->atTime($date, $startTime);
            $filterEnd = $this->atTime($date, $endTime);
            foreach ([[$date->subDay(), true], [$date, false]] as [$scheduleDate, $previousDay]) {
                $entries = is_array($weekly[strtolower($scheduleDate->englishDayOfWeek)] ?? null)
                    ? $weekly[strtolower($scheduleDate->englishDayOfWeek)]
                    : [];
                foreach ($entries as $entry) {
                    if (! is_array($entry) || ! is_string($entry['start'] ?? null) || ! is_string($entry['end'] ?? null)) {
                        continue;
                    }
                    $start = $this->atTime($scheduleDate, $entry['start']);
                    $end = $this->atTime($scheduleDate, $entry['end']);
                    if ($end->lessThanOrEqualTo($start)) {
                        $end = $end->addDay();
                    }
                    if ($previousDay && $end->lessThanOrEqualTo($date)) {
                        continue;
                    }
                    $clippedStart = $start->greaterThan($filterStart) ? $start : $filterStart;
                    $clippedEnd = $end->lessThan($filterEnd) ? $end : $filterEnd;
                    if ($clippedEnd->greaterThan($clippedStart)) {
                        $windows[] = ['start' => $clippedStart->utc(), 'end' => $clippedEnd->utc()];
                    }
                }
            }
        }

        return $this->intervals->union($windows);
    }

    private function atTime(CarbonImmutable $date, string $clock): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $clock));

        return $date->startOfDay()->setTime($hour, $minute);
    }
}
