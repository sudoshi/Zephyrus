<?php

namespace Database\Factories\Pharmacy;

use App\Models\Pharmacy\MedicationOrder;
use App\Models\Pharmacy\Preparation;
use Database\Factories\Pharmacy\Concerns\CreatesPharmacyFixtures;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Preparation> */
class PreparationFactory extends Factory
{
    use CreatesPharmacyFixtures;

    protected $model = Preparation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prep_uuid' => (string) Str::uuid(),
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->ivBatch()->create()->rx_order_id,
            'source_id' => fn (array $attributes): int => (int) MedicationOrder::query()->findOrFail($attributes['rx_order_id'])->source_id,
            'source_prep_key' => 'rx-prep-'.Str::uuid(),
            'prep_type' => 'iv_batch',
            'prep_branch' => 'iv_room',
            'batch_ref' => 'iv-batch-'.now()->format('Ymd').'-1',
            'prep_state' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'checked_at' => null,
            'cancelled_at' => null,
            'bud_expires_at' => null,
            'demo_owner' => null,
            'metadata' => [],
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'prep_state' => 'in_progress',
            'started_at' => now()->subMinutes(20),
        ]);
    }

    public function complete(): static
    {
        $started = now()->subMinutes(35);

        return $this->state(fn (): array => [
            'prep_state' => 'complete',
            'started_at' => $started,
            'completed_at' => $started->copy()->addMinutes(25),
            'bud_expires_at' => $started->copy()->addHours(24),
        ]);
    }

    public function checked(): static
    {
        return $this->complete()->state(fn (): array => [
            'prep_state' => 'checked',
            'checked_at' => now()->subMinutes(5),
        ]);
    }

    public function chemo(): static
    {
        return $this->state(fn (): array => [
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->chemo()->create()->rx_order_id,
            'prep_type' => 'chemo',
            'batch_ref' => null,
        ]);
    }

    public function tpn(): static
    {
        return $this->state(fn (): array => [
            'rx_order_id' => fn (): int => (int) MedicationOrder::factory()->tpn()->create()->rx_order_id,
            'prep_type' => 'tpn',
            'batch_ref' => 'tpn-cycle-'.now()->format('Ymd'),
        ]);
    }
}
