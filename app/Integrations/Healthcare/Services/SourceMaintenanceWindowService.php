<?php

namespace App\Integrations\Healthcare\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

final class SourceMaintenanceWindowService
{
    /**
     * @return array{
     *   configured: bool,
     *   active: bool,
     *   timezone: ?string,
     *   fingerprint: ?string,
     *   startsAtIso: ?string,
     *   endsAtIso: ?string
     * }
     */
    public function at(object $onboarding, CarbonImmutable $observedAt): array
    {
        $timezone = trim((string) ($onboarding->maintenance_timezone ?? ''));
        $windows = $this->windows($onboarding->maintenance_windows ?? null);
        if ($timezone === '' || $windows === [] || ! $this->timezoneExists($timezone)) {
            return $this->missing($timezone !== '' ? $timezone : null);
        }

        $local = $observedAt->setTimezone($timezone);
        foreach ($windows as $window) {
            if (! $this->valid($window)) {
                continue;
            }
            foreach ([0, 1] as $daysBefore) {
                $date = $local->startOfDay()->subDays($daysBefore);
                if ($date->dayOfWeek !== (int) $window['weekday']) {
                    continue;
                }
                [$hour, $minute] = array_map('intval', explode(':', (string) $window['start_local']));
                $start = CarbonImmutable::create(
                    $date->year,
                    $date->month,
                    $date->day,
                    $hour,
                    $minute,
                    0,
                    $timezone,
                );
                $end = $start->addMinutes((int) $window['duration_minutes']);
                if ($local->greaterThanOrEqualTo($start) && $local->lessThan($end)) {
                    return [
                        'configured' => true,
                        'active' => true,
                        'timezone' => $timezone,
                        'fingerprint' => $this->fingerprint($timezone, $window),
                        'startsAtIso' => $start->toIso8601String(),
                        'endsAtIso' => $end->toIso8601String(),
                    ];
                }
            }
        }

        return [
            'configured' => true,
            'active' => false,
            'timezone' => $timezone,
            'fingerprint' => null,
            'startsAtIso' => null,
            'endsAtIso' => null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function windows(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $window */
    private function valid(array $window): bool
    {
        return isset($window['weekday'], $window['start_local'], $window['duration_minutes'])
            && is_numeric($window['weekday'])
            && (int) $window['weekday'] >= 0
            && (int) $window['weekday'] <= 6
            && is_string($window['start_local'])
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $window['start_local']) === 1
            && is_numeric($window['duration_minutes'])
            && (int) $window['duration_minutes'] >= 15
            && (int) $window['duration_minutes'] <= 1440;
    }

    /** @param array<string, mixed> $window */
    private function fingerprint(string $timezone, array $window): string
    {
        $authority = [
            'duration_minutes' => (int) $window['duration_minutes'],
            'start_local' => (string) $window['start_local'],
            'timezone' => $timezone,
            'weekday' => (int) $window['weekday'],
        ];

        return hash('sha256', json_encode($authority, JSON_THROW_ON_ERROR));
    }

    private function timezoneExists(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{configured:bool, active:bool, timezone:?string, fingerprint:?string, startsAtIso:?string, endsAtIso:?string} */
    private function missing(?string $timezone): array
    {
        return [
            'configured' => false,
            'active' => false,
            'timezone' => $timezone,
            'fingerprint' => null,
            'startsAtIso' => null,
            'endsAtIso' => null,
        ];
    }
}
