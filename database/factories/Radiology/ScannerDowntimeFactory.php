<?php

namespace Database\Factories\Radiology;

use App\Models\Radiology\Scanner;
use App\Models\Radiology\ScannerDowntime;
use Database\Factories\Radiology\Concerns\CreatesRadiologyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ScannerDowntime> */
class ScannerDowntimeFactory extends Factory
{
    use CreatesRadiologyFixtures;

    protected $model = ScannerDowntime::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = now()->addHour();

        return [
            'downtime_uuid' => (string) Str::uuid(),
            'rad_scanner_id' => fn (): int => (int) Scanner::factory()->create()->rad_scanner_id,
            'source_id' => fn (array $attributes): int => (int) Scanner::query()->findOrFail($attributes['rad_scanner_id'])->source_id,
            'source_downtime_key' => 'downtime-'.Str::uuid(),
            'status' => 'scheduled',
            'reason_code' => 'PREVENTIVE_MAINTENANCE',
            'label' => 'Scheduled maintenance',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => 'active', 'starts_at' => now()->subMinutes(30), 'ends_at' => null]);
    }
}
