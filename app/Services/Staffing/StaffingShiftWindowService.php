<?php

namespace App\Services\Staffing;

use App\Models\Staffing\StaffingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StaffingShiftWindowService
{
    /** @return array{starts_at:CarbonImmutable,ends_at:CarbonImmutable,timezone:string} */
    public function forRequest(StaffingRequest $request): array
    {
        $timezone = (string) data_get($request->metadata, 'timezone', config('staffing.default_timezone'));

        return $this->forDateAndShift(
            $request->shift_date?->toDateString() ?? now($timezone)->toDateString(),
            (string) $request->shift,
            $timezone,
        );
    }

    /** @return array{starts_at:CarbonImmutable,ends_at:CarbonImmutable,timezone:string} */
    public function forDateAndShift(string $date, string $shift, ?string $timezone = null): array
    {
        $timezone = $timezone ?: (string) config('staffing.default_timezone');
        $definition = config("staffing.shifts.{$shift}");
        if (! is_array($definition) || ! isset($definition['starts_at'], $definition['ends_at'])) {
            throw ValidationException::withMessages(['shift' => 'Unknown staffing shift.']);
        }

        try {
            $startsAt = CarbonImmutable::parse("{$date} {$definition['starts_at']}", $timezone);
            $endsAt = CarbonImmutable::parse("{$date} {$definition['ends_at']}", $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['timezone' => 'The staffing shift timezone is invalid.']);
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return [
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
            'timezone' => $timezone,
        ];
    }
}
